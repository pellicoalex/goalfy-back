<?php

namespace App\Services;

use App\Database\DB;

class TournamentService
{
    /**
     * Genero bracket fisso 8 squadre:
     * round 1: quarti (4)
     * round 2: semifinali (2)
     * round 3: finale (1)
     */
    public function generateBracket8(int $tournamentId): void
    {
        DB::transaction(function () use ($tournamentId) {

            // 1) torneo esiste?
            $t = DB::select("SELECT * FROM tournaments WHERE id = :id", ['id' => $tournamentId]);
            if (empty($t)) {
                throw new \Exception("Torneo non trovato");
            }

            // 2) partecipanti = 8
            $participants = DB::select(
                "SELECT team_id
                 FROM tournament_participants
                 WHERE tournament_id = :id
                 ORDER BY COALESCE(seed, 999999), team_id",
                ['id' => $tournamentId]
            );

            if (count($participants) !== 8) {
                throw new \Exception("Per generare il bracket servono ESATTAMENTE 8 squadre (attuali: " . count($participants) . ")");
            }

            // 3) non rigenerare se esistono match
            $existing = DB::select("SELECT id FROM matches WHERE tournament_id = :id LIMIT 1", ['id' => $tournamentId]);
            if (!empty($existing)) {
                throw new \Exception("Bracket già generato per questo torneo");
            }

            // 4)  accoppiamenti
            $teamIds = array_map(fn($r) => (int)$r['team_id'], $participants);
            //shuffle($teamIds);   ordine casuale

            // 5) crea finale + semifinali 
            $finalId = DB::insertReturningId(
                "INSERT INTO matches (tournament_id, round, match_number, status)
                 VALUES (:tid, 3, 1, 'waiting'::match_status)
                 RETURNING id",
                ['tid' => $tournamentId]
            );

            $sf1Id = DB::insertReturningId(
                "INSERT INTO matches (tournament_id, round, match_number, status, next_match_id, next_slot)
                 VALUES (:tid, 2, 1, 'waiting'::match_status, :nextId, 'A')
                 RETURNING id",
                ['tid' => $tournamentId, 'nextId' => $finalId]
            );

            $sf2Id = DB::insertReturningId(
                "INSERT INTO matches (tournament_id, round, match_number, status, next_match_id, next_slot)
                 VALUES (:tid, 2, 2, 'waiting'::match_status, :nextId, 'B')
                 RETURNING id",
                ['tid' => $tournamentId, 'nextId' => $finalId]
            );

            // 6) quarti -> semifinali
            $qf = [
                ['num' => 1, 'next' => $sf1Id, 'slot' => 'A'],
                ['num' => 2, 'next' => $sf1Id, 'slot' => 'B'],
                ['num' => 3, 'next' => $sf2Id, 'slot' => 'A'],
                ['num' => 4, 'next' => $sf2Id, 'slot' => 'B'],
            ];

            for ($i = 0; $i < 4; $i++) {
                $a = $teamIds[$i * 2] ?? null;
                $b = $teamIds[$i * 2 + 1] ?? null;

                $status = ($a && $b) ? 'scheduled' : 'waiting';

                DB::insert(
                    "INSERT INTO matches
                      (tournament_id, round, match_number, status, team_a_id, team_b_id, next_match_id, next_slot)
                     VALUES
                      (:tid, 1, :num, :status::match_status, :a, :b, :nextId, :slot)",
                    [
                        'tid' => $tournamentId,
                        'num' => $qf[$i]['num'],
                        'status' => $status,
                        'a' => $a,
                        'b' => $b,
                        'nextId' => $qf[$i]['next'],
                        'slot' => $qf[$i]['slot'],
                    ]
                );
            }

            // 7) stato torneo
            DB::update(
                "UPDATE tournaments SET status = 'ongoing', updated_at = now() WHERE id = :id",
                ['id' => $tournamentId]
            );

            // check 7 match
            $cnt = DB::select("SELECT COUNT(*) AS c FROM matches WHERE tournament_id = :id", ['id' => $tournamentId]);
            $created = (int)($cnt[0]['c'] ?? 0);
            if ($created !== 7) {
                throw new \Exception("Errore: bracket non creato correttamente (match creati: {$created})");
            }
        });
    }

    /**
     * Salva risultato match e avanza vincitore.

     */
    public function setMatchResult(
        int $matchId,
        int $scoreA,
        int $scoreB,
        array $participations = [],
        array $goalEvents = []
    ): void {
        if ($scoreA < 0 || $scoreB < 0) {
            throw new \Exception("I punteggi non possono essere negativi");
        }
        if ($scoreA === $scoreB) {
            throw new \Exception("Nel torneo a eliminazione diretta non è ammesso il pareggio");
        }

        DB::transaction(function () use ($matchId, $scoreA, $scoreB, $participations, $goalEvents) {

            //aggiorna matches, e evitare che riscriva gaolevents e presenze

            $rows = DB::select("SELECT * FROM matches WHERE id = :id", ['id' => $matchId]);
            if (empty($rows)) {
                throw new \Exception("Match non trovato");
            }
            $m = $rows[0];

            // blocca modifiche se match già confermato
            if (($m['status'] ?? null) === 'played') {
                throw new \Exception("Match già confermato (played): risultato bloccato.");
            }


            if (empty($m['team_a_id']) || empty($m['team_b_id'])) {
                throw new \Exception("Match non valido: manca una squadra (status waiting)");
            }

            $teamAId = (int)$m['team_a_id'];
            $teamBId = (int)$m['team_b_id'];

            // 1) salva punteggio + winner
            $winnerId = ($scoreA > $scoreB) ? $teamAId : $teamBId;

            error_log("STEP 1: match loaded OK");

            error_log("STEP 2: update match START");

            if (!empty($participations) || !empty($goalEvents)) {

                DB::delete("DELETE FROM match_goal_events WHERE match_id = :mid", ['mid' => $matchId]);
                DB::delete("DELETE FROM match_player_participations WHERE match_id = :mid", ['mid' => $matchId]);
            }

            DB::update(
                "UPDATE matches
                SET score_a = :sa,
                score_b = :sb,
                winner_team_id = :w,
                status = 'played'::match_status,
                updated_at = now()
                WHERE id = :id",
                ['sa' => $scoreA, 'sb' => $scoreB, 'w' => $winnerId, 'id' => $matchId]
            );

            //il match ora è ufficialmente giocato



            //  salva presenze + goal events
            //  Solo se payload non vuoto
            if (!empty($participations) || !empty($goalEvents)) {

                // pulizia sicura per ri-salvataggi (solo per questo match)
                DB::delete("DELETE FROM match_goal_events WHERE match_id = :mid", ['mid' => $matchId]);
                DB::delete("DELETE FROM match_player_participations WHERE match_id = :mid", ['mid' => $matchId]);

                // inserisci partecipazioni

                foreach ($participations as $p) {
                    $playerId = (int)($p['player_id'] ?? 0);
                    $teamId   = (int)($p['team_id'] ?? 0);

                    if ($playerId <= 0 || $teamId <= 0) {
                        continue; // skip righe incomplete
                    }
                    // sicurezza: solo team A o B
                    if ($teamId !== $teamAId && $teamId !== $teamBId) {
                        continue;
                    }

                    DB::insert(
                        "INSERT INTO match_player_participations
                         (match_id, player_id, team_id, created_at)
                         VALUES
                         (:mid, :pid, :tid, now())",
                        [
                            'mid' => $matchId,
                            'pid' => $playerId,
                            'tid' => $teamId,
                        ]
                    );
                }

                // inserisci goal events (SAFE)
                foreach ($goalEvents as $e) {
                    $scorerId = (int)($e['scorer_player_id'] ?? 0);
                    $teamId   = (int)($e['team_id'] ?? 0);

                    if ($scorerId <= 0 || $teamId <= 0) continue;
                    if ($teamId !== $teamAId && $teamId !== $teamBId) continue;

                    // assist: '' / 0 / '0' -> NULL
                    $assistRaw = $e['assist_player_id'] ?? null;
                    if ($assistRaw === null || $assistRaw === '' || $assistRaw === 0 || $assistRaw === '0') {
                        $assistId = null;
                    } else {
                        $assistId = (int)$assistRaw;
                        if ($assistId <= 0) $assistId = null;
                    }

                    // assist != scorer
                    if ($assistId !== null && $assistId === $scorerId) {
                        $assistId = null;
                    }

                    // minute: default 0
                    $minuteRaw = $e['minute'] ?? null;
                    if (is_string($minuteRaw)) {
                        preg_match('/\d+/', $minuteRaw, $m);
                        $minuteRaw = $m[0] ?? null;
                    }
                    $minute = ($minuteRaw === null || $minuteRaw === '') ? 0 : (int)$minuteRaw;
                    if ($minute < 0) $minute = 0;

                    DB::insert(
                        "INSERT INTO match_goal_events
         (match_id, team_id, scorer_player_id, assist_player_id, minute, created_at)
         VALUES
         (:mid, :tid, :scorer, :assist, :minute, now())",
                        [
                            'mid' => $matchId,
                            'tid' => $teamId,
                            'scorer' => $scorerId,
                            'assist' => $assistId,
                            'minute' => $minute,
                        ]
                    );
                }
            }

            // avanza al match successivo 
            if (!empty($m['next_match_id'])) {
                $nextId = (int)$m['next_match_id'];
                $slot = $m['next_slot'];

                if ($slot === 'A') {
                    DB::update(
                        "UPDATE matches SET team_a_id = :w, updated_at = now() WHERE id = :id",
                        ['w' => $winnerId, 'id' => $nextId]
                    );
                } elseif ($slot === 'B') {
                    DB::update(
                        "UPDATE matches SET team_b_id = :w, updated_at = now() WHERE id = :id",
                        ['w' => $winnerId, 'id' => $nextId]
                    );
                } else {
                    throw new \Exception("next_slot non valido");
                }

                DB::update(
                    "UPDATE matches
                     SET status = CASE
                       WHEN team_a_id IS NOT NULL AND team_b_id IS NOT NULL THEN 'scheduled'::match_status
                       ELSE 'waiting'::match_status
                     END,
                     updated_at = now()
                     WHERE id = :id",
                    ['id' => $nextId]
                );
            } else {
                $tournamentId = (int)$m['tournament_id'];

                DB::update(
                    "UPDATE tournaments
                     SET status = 'completed', winner_team_id = :w, updated_at = now()
                     WHERE id = :id",
                    ['w' => $winnerId, 'id' => $tournamentId]
                );
            }
        });
    }

    /**
     * Statistiche aggregate giocatore:
     * - goals: count goal dove è scorer
     * - assists: count goal dove è assist
     * - appearances: count presenze (match distinti in cui ha partecipato) in ogni squadra tutti prendono la presenza
     */
    public function getPlayerAggregatedStats(int $playerId): array
    {
        if ($playerId <= 0) {
            throw new \Exception("playerId non valido");
        }

        $goalsRow = DB::select(
            "SELECT COUNT(*)::int AS goals
         FROM match_goal_events
         WHERE scorer_player_id = :pid",
            ['pid' => $playerId]
        );

        $assistsRow = DB::select(
            "SELECT COUNT(*)::int AS assists
         FROM match_goal_events
         WHERE assist_player_id = :pid
           AND assist_player_id IS NOT NULL",
            ['pid' => $playerId]
        );

        $matchesRow = DB::select(
            "SELECT COUNT(DISTINCT match_id)::int AS matches
         FROM match_player_participations
         WHERE player_id = :pid",
            ['pid' => $playerId]
        );

        return [
            'matches' => (int)($matchesRow[0]['matches'] ?? 0),
            'goals'   => (int)($goalsRow[0]['goals'] ?? 0),
            'assists' => (int)($assistsRow[0]['assists'] ?? 0),
        ];
    }
}
