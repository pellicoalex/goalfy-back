<?php

use App\Utils\Response;
use App\Utils\Request;
use App\Models\Tournament;
use App\Database\DB;
use App\Models\MatchModel;
use App\Models\Team;
use App\Models\TournamentParticipant;
use App\Services\TournamentService;
use Pecee\SimpleRouter\SimpleRouter as Router;

// funzione helper per permettere sotto l'update e delete di tourments
function row_get($row, string $key, $default = null)
{
    if (is_array($row)) return $row[$key] ?? $default;
    if (is_object($row)) return $row->{$key} ?? $default;
    return $default;
}

function assertTournamentEditableOrDeletable(int $tournamentId): void
{
    $row = DB::select(
        "SELECT id, status
         FROM tournaments
         WHERE id = :id
         LIMIT 1",
        ['id' => $tournamentId]
    )[0] ?? null;

    if (!$row) {
        throw new \Exception("Torneo non trovato");
    }

    $status = strtolower((string) row_get($row, 'status', ''));
    if ($status === 'completed') {
        throw new \Exception("Operazione non consentita: il torneo è concluso (completed).");
    }

    // RISULTATI = match played OR goal events
    $hasResultsRow = DB::select(
        "SELECT
          (
            EXISTS (
              SELECT 1
              FROM matches m
              WHERE m.tournament_id = :tid
                AND m.status = 'played'::match_status
            )
            OR
            EXISTS (
              SELECT 1
              FROM match_goal_events ge
              JOIN matches m2 ON m2.id = ge.match_id
              WHERE m2.tournament_id = :tid
            )
          ) AS has_results",
        ['tid' => $tournamentId]
    )[0] ?? null;

    $hasResults = (bool) row_get($hasResultsRow, 'has_results', false);

    if ($hasResults) {
        throw new \Exception("Operazione non consentita: il torneo contiene già risultati.");
    }
}


/* Router::get('/tournaments', function () {
    try {
        $rows = DB::select(
            "SELECT t.*,
                    w.name     AS winner_name,
                    w.logo_url AS winner_team_logo_url
             FROM tournaments t
             LEFT JOIN teams w ON w.id = t.winner_team_id
             ORDER BY t.start_date DESC, t.id DESC"
        );
        Response::success($rows)->send();
    } catch (\Exception $e) {
        Response::error('Errore nel recupero tornei: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
}); */

/**
 * GET /tournaments/- lista tornei
 */

//Recupero tutti i tornei (tournaments t), join “soft” sul team vincitore tramite LEFT JOIN teams w ON w.id = t.winner_team_id.
//così posso mostrare nome/logo del winner senza perdere tornei senza winner.
//Calcolo due flag per ogni torneo: has_results: esiste almeno un goal event in quel torneo e has_matches: esiste almeno un match in quel torneo.
//Ordino per data di inizio e id decrescente (più recenti sopra).
// sto costruendo una lista per frontend (non solo tournaments raw).

Router::get('/tournaments', function () {
    try {
        $rows = DB::select(
            "SELECT t.*,
                    w.name     AS winner_name,
                    w.logo_url AS winner_team_logo_url,

                    EXISTS (
                      SELECT 1
                      FROM match_goal_events ge
                      JOIN matches m ON m.id = ge.match_id
                      WHERE m.tournament_id = t.id
                      LIMIT 1
                    ) AS has_results,

                    EXISTS (
                      SELECT 1
                      FROM matches m2
                      WHERE m2.tournament_id = t.id
                      LIMIT 1
                    ) AS has_matches

             FROM tournaments t
             LEFT JOIN teams w ON w.id = t.winner_team_id
             ORDER BY t.start_date DESC, t.id DESC"
        );

        Response::success($rows)->send();
    } catch (\Exception $e) {
        Response::error(
            'Errore nel recupero tornei: ' . $e->getMessage(),
            Response::HTTP_INTERNAL_SERVER_ERROR
        )->send();
    }
});




/**
 * GET /tournaments/{id} - dettaglio torneo + partecipanti
 */

//Cercho il torneo con SELECT * FROM tournaments WHERE id = :id, Se non lo trovo → 404.
//Recupero i partecipanti, prendo tournament_participants e join con teams per avere il nome squadra,ordino per seed (e fallback alto se seed nullo).
//Ritorno: tournament: ...e participants: ...
// costruisco la schermata “dettaglio torneo”.

Router::get('/tournaments/{id}', function ($id) {
    try {
        $t = DB::select("SELECT * FROM tournaments WHERE id = :id", ['id' => (int)$id]);
        if (empty($t)) {
            Response::error('Torneo non trovato', Response::HTTP_NOT_FOUND)->send();
        }

        $participants = DB::select(
            "SELECT tp.team_id, tm.name
             FROM tournament_participants tp
             JOIN teams tm ON tm.id = tp.team_id
             WHERE tp.tournament_id = :id
             ORDER BY COALESCE(tp.seed, 999999), tp.team_id",
            ['id' => (int)$id]
        );

        Response::success([
            'tournament' => $t[0],
            'participants' => $participants
        ])->send();
    } catch (\Exception $e) {
        Response::error('Errore nel recupero torneo: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});

/**
 * POST /tournaments - crea torneo
 * body: { name, start_date }
 */
/* Router::post('/tournaments', function () {
    try {
        $request = new Request();
        $data = $request->json();

        $errors = Tournament::validate($data);
        if (!empty($errors)) {
            Response::error('Errore di validazione', Response::HTTP_BAD_REQUEST, $errors)->send();
            return;
        }

        // default status draft
        if (!isset($data['status'])) $data['status'] = 'draft';

        $tournament = Tournament::create($data);
        Response::success($tournament, Response::HTTP_CREATED, 'Torneo creato')->send();
    } catch (\Exception $e) {
        Response::error('Errore durante creazione torneo: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
}); */

/**
 * POST /tournaments - crea torneo
 * body: { name, start_date }
 */
//Leggo JSON body, validazione con Tournament::validate, normalizzo name e prendi start_date.imposti status con default draft.
//Inserisco nel DB usando RETURNING *: INSERT INTO tournaments (...) RETURNING *.
//Ritorni il torneo creato.
//Qui ho scelto di non usare il Model ma una query manuale perché voglio subito il record completo “RETURNING *”.

Router::post('/tournaments', function () {
    try {
        $request = new Request();
        $data = $request->json() ?? [];

        $errors = Tournament::validate($data);
        if (!empty($errors)) {
            Response::error('Errore di validazione', Response::HTTP_BAD_REQUEST, $errors)->send();
            return;
        }

        $name = trim((string)($data['name'] ?? ''));
        $startDate = $data['start_date'] ?? null;

        // default status draft
        $status = $data['status'] ?? 'draft';

        $rows = DB::select(
            "INSERT INTO tournaments (name, start_date, status, created_at, updated_at)
             VALUES (:name, :start_date, :status::tournament_status, now(), now())
             RETURNING *",
            [
                'name' => $name,
                'start_date' => $startDate,
                'status' => $status,
            ]
        );

        $tournament = $rows[0] ?? null;
        if (!$tournament) {
            throw new \Exception("Creazione torneo fallita");
        }

        Response::success($tournament, Response::HTTP_CREATED, 'Torneo creato')->send();
    } catch (\Exception $e) {
        Response::error(
            'Errore durante creazione torneo: ' . $e->getMessage(),
            Response::HTTP_INTERNAL_SERVER_ERROR
        )->send();
    }
});


/**
 * POST /tournaments/{id}/participants
 * body: { team_ids: [1..8] }
 */
/* Router::post('/tournaments/{id}/participants', function ($id) {
    try {
        $request = new Request();
        $data = $request->json();

        $teamIds = $data['team_ids'] ?? null;
        if (!is_array($teamIds)) {
            Response::error('team_ids deve essere un array', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        // normalizza + unici
        $teamIds = array_values(array_unique(array_map('intval', $teamIds)));

        if (count($teamIds) > 8) {
            Response::error('Massimo 8 squadre per torneo', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        // DB::transaction(function () use ($id, $teamIds) {

        $id = (int)$id;

        // 1) torneo esiste
        $t = DB::select(
            "SELECT id FROM tournaments WHERE id = :id",
            ['id' => $id]
        );

        if (empty($t)) {
            throw new \Exception('Torneo non trovato');
        }

        // 2) blocca se bracket già generato
        $hasMatches = DB::select(
            "SELECT 1 FROM matches WHERE tournament_id = :id LIMIT 1",
            ['id' => $id]
        );

        if (!empty($hasMatches)) {
            throw new \Exception(
                "Impossibile modificare i partecipanti: bracket già generato. Resetta i match prima di aggiornare i partecipanti."
            );
        }


        // 3) reset participants
        DB::delete(
            "DELETE FROM tournament_participants WHERE tournament_id = :id",
            ['id' => $id]
        );

        // 4) insert participants
        foreach ($teamIds as $idx => $teamId) {
            // error_log("INSERT INTO tournament_participants (tournament_id, team_id, seed, created_at) VALUES ($id, $teamId, $idx + 1, now())");
            // DB::insert(
            //     "INSERT INTO tournament_participants (tournament_id, team_id, seed, created_at) VALUES (:tid, :team, :seed, now())",
            //     [
            //         ':tid'  => $id,
            //         ':team' => $teamId,
            //         ':seed' => $idx + 1
            //     ]
            // );
            TournamentParticipant::create([
                "tournament_id" => $id,
                "team_id" => (int)$teamId,
                "seed" => $idx + 1
            ]);
        }
        // });


        Response::success(null, Response::HTTP_OK, 'Partecipanti salvati')->send();
    } catch (\Exception $e) {
        Response::error('Errore salvataggio partecipanti: ' . $e->getMessage(), Response::HTTP_BAD_REQUEST)->send();
    }
}); */




/**
 * POST /tournaments/{id}/participants
 * body: { team_ids: [1..8] }
 */
//Leggo JSON e pretendi team_ids come array, Normalizzo cast a int, rimuovi duplicati ,Applichi la regola che devono devono essere esattamente 8 (si parte dai quarti)
//Avvio una transaction perché farò più operazioni collegate.Dentro transaction: chiamo assertTournamentEditableOrDeletable → bloccho se completed o con risultati.
//controllo che il torneo esista, controlli che NON esistano match già generati (bracket già creato) → se sì bloccho.
//cancello eventuali partecipanti precedenti, inserisco i nuovi partecipanti uno a uno con seed (1..8)
//Fuori transaction: ritorno OK. Qui la transaction serve perché delete + insert multipli o tutti inseriti, o nessuno.

Router::post('/tournaments/{id}/participants', function ($id) {
    try {
        $request = new Request();
        $data = $request->json() ?? [];

        $teamIds = $data['team_ids'] ?? null;
        if (!is_array($teamIds)) {
            Response::error('team_ids deve essere un array', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        // normalizza + unici
        $teamIds = array_values(array_unique(array_map('intval', $teamIds)));

        /* if (count($teamIds) > 8) {
            Response::error('Massimo 8 squadre per torneo', Response::HTTP_BAD_REQUEST)->send();
            return;
        } */


        if (count($teamIds) !== 8) {
            Response::error('Devi selezionare esattamente 8 squadre', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        $tid = (int)$id;

        DB::transaction(function () use ($tid, $teamIds) {
            // nuova regola: NO completed, NO risultati
            assertTournamentEditableOrDeletable($tid);

            $t = DB::select(
                "SELECT id FROM tournaments WHERE id = :id LIMIT 1",
                ['id' => $tid]
            );
            if (empty($t)) {
                throw new \Exception('Torneo non trovato');
            }

            // 2) blocca se bracket già generato (match esistenti)
            $hasMatches = DB::select(
                "SELECT 1 FROM matches WHERE tournament_id = :id LIMIT 1",
                ['id' => $tid]
            );
            if (!empty($hasMatches)) {
                throw new \Exception(
                    "Impossibile modificare i partecipanti: bracket già generato. Resetta i match prima di aggiornare i partecipanti."
                );
            }

            // 3) reset participants
            DB::delete(
                "DELETE FROM tournament_participants WHERE tournament_id = :id",
                ['id' => $tid]
            );

            // 4) insert participants
            foreach ($teamIds as $idx => $teamId) {
                TournamentParticipant::create([
                    "tournament_id" => $tid,
                    "team_id"       => (int)$teamId,
                    "seed"          => $idx + 1,
                ]);
            }
        });

        Response::success(null, Response::HTTP_OK, 'Partecipanti salvati')->send();
    } catch (\Exception $e) {
        Response::error('Errore salvataggio partecipanti: ' . $e->getMessage(), Response::HTTP_BAD_REQUEST)->send();
    }
});


/**
 * GET /tournaments/{id}/participants
 */
//Recupero i partecipanti con TournamentParticipant::where(...), per ciascun partecipante lo converto in array.
//faccio Team::find(team_id) per agganciare l’oggetto team, costruisco una lista result, ritorni la lista arricchita.
// endpoint che fa “participants + team info”, ma lo fa con N+1 query (una per team), arricchisco i partecipanti con i dati del team.

Router::get(
    '/tournaments/{id}/participants',
    function ($id) {
        $participants = TournamentParticipant::where("tournament_id", "=", (int)$id);

        $result = [];
        foreach ($participants as $participant) {
            $participantData = $participant->toArray();
            $team = Team::find($participant->team_id);
            $participantData['team'] = $team;
            $result[] = $participantData;
        }

        Response::success($result, Response::HTTP_OK, 'Bracket generato')->send();
    }
);



/**
 * PUT PATCH /tournaments/{id}/matches
 * PUT -> /tournaments/{id}/matches che fa l'update dei match per questo torneo
 */
//Leggo body e fisso matches come array non vuoto, controlli che il torneo esista, carico tutti i match del torneo dal DB (id, team_a_id, team_b_id).
//Costruisco una mappa matchMap per verificare appartenenza match→torneo, se non ci sono match → errore “genera bracket prima”.
//Loop sugli items del payload, estraggo matchId (supporta chiavi diverse)
//se matchId non appartiene al torneo → errore, trovo il match con MatchModel::find(matchId).
//faccio update dei team_a_id / team_b_id con i valori del payload ,ritorno OK.
//aggiorno manualmente gli accoppiamenti dei match di un torneo.

Router::match(['put', 'patch'], '/tournaments/{id}/matches', function ($id) {
    try {
        $tid = (int)$id;
        $request = new Request();
        $data = $request->json() ?? [];

        $items = $data['matches'] ?? null;
        if (!is_array($items) || empty($items)) {
            Response::error('matches deve essere un array non vuoto', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        // torneo esiste?
        $t = DB::select("SELECT id FROM tournaments WHERE id = :id", ['id' => $tid]);
        if (empty($t)) {
            Response::error('Torneo non trovato', Response::HTTP_NOT_FOUND)->send();
            return;
        }

        $dbMatches = DB::select(
            "SELECT id, team_a_id, team_b_id
             FROM matches
             WHERE tournament_id = :tid",
            ['tid' => $tid]
        );

        $matchMap = [];
        foreach ($dbMatches as $m) {
            $matchMap[(int)$m['id']] = [
                'team_a_id' => $m['team_a_id'] !== null ? (int)$m['team_a_id'] : null,
                'team_b_id' => $m['team_b_id'] !== null ? (int)$m['team_b_id'] : null,
            ];
        }

        if (empty($matchMap)) {
            Response::error('Nessun match trovato per questo torneo (genera prima il bracket)', Response::HTTP_BAD_REQUEST)->send();
            return;
        }



        foreach ($items as $it) {
            if (!is_array($it)) continue;

            $matchId = (int)($it['match_id'] ?? $it['matchId'] ?? $it['id'] ?? 0);
            if ($matchId <= 0) continue;

            if (!isset($matchMap[$matchId])) {
                Response::error("Il match {$matchId} non appartiene al torneo {$tid}", Response::HTTP_BAD_REQUEST)->send();
                return;
            }
            /*  $cleanItems[$matchId] = [
                'team_a_id' => $it['team_a_id'] !== null ? (int)$it['team_a_id'] : null,
                'team_b_id' => $it['team_b_id'] !== null ? (int)$it['team_b_id'] : null,
            ]; */
            $match = MatchModel::find($matchId);
            if (!isset($match)) {
                Response::error("Nessun match trovato con id $matchId", Response::HTTP_BAD_REQUEST)->send();
                return;
            }
            $match->update(["team_a_id" => $it["team_a_id"] ?? null, "team_b_id" => $it["team_b_id"] ?? null]);
        }

        // if (empty($cleanItems)) {
        //     Response::error('Nessun match valido nel payload', Response::HTTP_BAD_REQUEST)->send();
        //     return;
        // }


        Response::success(
            null,
            Response::HTTP_OK,
            'Match aggiornati e avanzamento completato'
        )->send();
    } catch (\Exception $e) {
        Response::error('Errore update match torneo: ' . $e->getMessage(), Response::HTTP_BAD_REQUEST)->send();
    }
});





/**
 * POST /tournaments/{id}/generate-bracket
 * genera i 7 match (in quanto si parte dai quarti) e imposta i link
 */
//Creo TournamentService, chiamo generateBracket8(tournamentId): generazione match quarti/semifinali/finale.
//link tra match (avanzamenti), ritorno OK.
//Quila logica complessa l'ho spostatta nel service.

Router::post('/tournaments/{id}/generate-bracket', function ($id) {
    try {
        $service = new TournamentService();
        $service->generateBracket8((int)$id);

        Response::success(null, Response::HTTP_OK, 'Bracket generato')->send();
    } catch (\Exception $e) {
        Response::error('Errore generazione bracket: ' . $e->getMessage(), Response::HTTP_BAD_REQUEST)->send();
    }
});


/* Router::get('/tournaments/{id}/bracket', function ($id) {
    try {
        $matches = DB::select(
            "SELECT m.*,
                    ta.name AS team_a_name,
                    tb.name AS team_b_name,
                    w.name  AS winner_name
             FROM matches m
             LEFT JOIN teams ta ON ta.id = m.team_a_id
             LEFT JOIN teams tb ON tb.id = m.team_b_id
             LEFT JOIN teams w  ON w.id  = m.winner_team_id
             WHERE m.tournament_id = :id
             ORDER BY m.round ASC, m.match_number ASC",
            ['id' => (int)$id]
        );

        Response::success($matches)->send();
    } catch (\Exception $e) {
        Response::error('Errore recupero bracket: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
}); */



/**
 * GET /tournaments/{id}/bracket mi serve per reactflow sul front  vecchia commentata
 */
//Recupero tutti i match del torneo con join su team A/B e winner:nomi e loghi per ReactFlow .Prendo tutti gli id dei match.
//Se ci sono match: costruisco una lista placeholders :m0, :m1, ... faccio query unica che prende tutti i goal events per quei match (con join players per i nomi/avatar).
//costruisco una mappa goalMap[match_id] = [events...]
//Per ogni match: aggiungo goal_events e goalEvents (doppia chiave per compatibilità front)
//Ritorni i match arricchiti,sto costruendo un payload “completo” per UI bracket: match + team + goal events.

Router::get('/tournaments/{id}/bracket', function ($id) {
    try {
        $matches = DB::select(
            "SELECT m.*,
                    ta.name     AS team_a_name,
                    tb.name     AS team_b_name,
                    ta.logo_url AS team_a_logo_url,
                    tb.logo_url AS team_b_logo_url,
                    w.name      AS winner_name
             FROM matches m
             LEFT JOIN teams ta ON ta.id = m.team_a_id
             LEFT JOIN teams tb ON tb.id = m.team_b_id
             LEFT JOIN teams w  ON w.id  = m.winner_team_id
             WHERE m.tournament_id = :id
             ORDER BY m.round ASC, m.match_number ASC",
            ['id' => (int)$id]
        );

        // controllare SE
        // --- goal events per tutti i match (fix bind placeholders) ---
        $matchIds = array_map(fn($m) => (int)$m['id'], $matches);

        $goalMap = [];
        if (!empty($matchIds)) {

            // placeholders named: :m0, :m1, :m2...
            $placeholders = [];
            $params = [];

            foreach ($matchIds as $i => $mid) {
                $k = ":m{$i}";
                $placeholders[] = $k;
                $params[$k] = $mid;
            }

            $in = implode(',', $placeholders);

            $goalRows = DB::select("
        SELECT
          ge.match_id,
          ge.id,
          ge.team_id,
          ge.scorer_player_id,
          ge.assist_player_id,
          ge.minute,

          sp.first_name AS scorer_first_name,
          sp.last_name  AS scorer_last_name,
          sp.avatar_url AS scorer_avatar_url,

          ap.first_name AS assist_first_name,
          ap.last_name  AS assist_last_name,
          ap.avatar_url AS assist_avatar_url
        FROM match_goal_events ge
        JOIN players sp ON sp.id = ge.scorer_player_id
        LEFT JOIN players ap ON ap.id = ge.assist_player_id
        WHERE ge.match_id IN ($in)
        ORDER BY ge.match_id ASC, ge.minute ASC NULLS LAST, ge.id ASC
    ", $params);

            foreach ($goalRows as $r) {
                $mid = (int)$r['match_id'];
                if (!isset($goalMap[$mid])) $goalMap[$mid] = [];
                $goalMap[$mid][] = $r;
            }
        }

        foreach ($matches as &$m) {
            $mid = (int)$m['id'];
            $evs = $goalMap[$mid] ?? [];


            $m['goal_events'] = $evs;
            $m['goalEvents']  = $evs;
        }
        unset($m);



        Response::success($matches)->send();
    } catch (\Exception $e) {
        Response::error('Errore recupero bracket: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});

/**
 * GET /tournaments/{id}/goal-events
 * Tutti i goal events del torneo (per lo storico)
 */
//Cerco tutti i goal events legati ai match del torneo tramite join match per filtrare m.tournament_id e join players per nominativi scorer/assist.
//Ordino per match, minuto, id,ritorno la lista (storico completo).

Router::get('/tournaments/{id}/goal-events', function ($id) {
    try {
        $tid = (int)$id;

        $rows = DB::select(
            "SELECT
                ge.id,
                ge.match_id,
                ge.team_id,
                ge.scorer_player_id,
                ge.assist_player_id,
                ge.minute,

                sp.first_name AS scorer_first_name,
                sp.last_name  AS scorer_last_name,
                sp.avatar_url AS scorer_avatar_url,

                ap.first_name AS assist_first_name,
                ap.last_name  AS assist_last_name,
                ap.avatar_url AS assist_avatar_url

             FROM match_goal_events ge
             JOIN matches m ON m.id = ge.match_id
             JOIN players sp ON sp.id = ge.scorer_player_id
             LEFT JOIN players ap ON ap.id = ge.assist_player_id

             WHERE m.tournament_id = :tid
             ORDER BY ge.match_id ASC, ge.minute ASC NULLS LAST, ge.id ASC",
            ['tid' => $tid]
        );

        Response::success($rows)->send();
    } catch (\Exception $e) {
        Response::error(
            'Errore recupero goal events torneo: ' . $e->getMessage(),
            Response::HTTP_INTERNAL_SERVER_ERROR
        )->send();
    }
});


/**
 * GET /tournaments/{id}/players
 * Tutti i giocatori comparsi nel torneo per le presenze
 */
//Voglio la lista unica dei giocatori comparsi nel torneo (presenze):
//parto da match_player_participations tramite join con matches, players.
//Aggancio info team tramite: tournament_participants e teams, uso SELECT DISTINCT per non duplicare lo stesso player.
//Ordino per nome team e nome giocatore, ritorno la lista.
//endpoint “report” per statistiche/overview torneo.

Router::get('/tournaments/{id}/players', function ($id) {
    try {
        $tid = (int)$id;

        $rows = DB::select(
            "SELECT DISTINCT
                p.id,
                p.first_name,
                p.last_name,
                p.avatar_url,
                p.role,

                tp.team_id,
                t.name AS team_name

             FROM match_player_participations mpp
             JOIN matches m ON m.id = mpp.match_id
             JOIN players p ON p.id = mpp.player_id
             LEFT JOIN tournament_participants tp
               ON tp.tournament_id = m.tournament_id
              AND tp.team_id = mpp.team_id
             LEFT JOIN teams t ON t.id = mpp.team_id

             WHERE m.tournament_id = :tid
             ORDER BY t.name ASC NULLS LAST, p.last_name ASC, p.first_name ASC",
            ['tid' => $tid]
        );

        Response::success($rows)->send();
    } catch (\Exception $e) {
        Response::error(
            'Errore recupero players torneo: ' . $e->getMessage(),
            Response::HTTP_INTERNAL_SERVER_ERROR
        )->send();
    }
});




/**
 * PATCH /tournaments/{id} - modifica torneo (solo draft e senza risultati)
 * body: { name?, start_date?, status? }
 */
//Converto id e prepari $updated = null. Avvio una transaction perché voglio controllare regole e aggiornare.
//Dentro transaction tramite assertTournamentEditableOrDeletable → bloccho completed o con risultati.
//leggo body JSON, costruisco dinamicamente i campi da aggiornare (name, start_date, status).
//se non c’è nulla da aggiornare → errore. Faccio UPDATE ... RETURNING * per ottenere il record aggiornato.
//lo salvo in $updated, fuori ritorno $updated.
//update “dinamico” con RETURNING (comodo per il frontend).

Router::match(['put', 'patch'], '/tournaments/{id}', function ($id) {
    try {
        $tid = (int)$id;
        $updated = null;

        DB::transaction(function () use ($tid, &$updated) {
            assertTournamentEditableOrDeletable($tid);

            $request = new Request();
            $data = $request->json() ?? [];

            $fields = [];
            $params = ['id' => $tid];

            if (isset($data['name'])) {
                $fields[] = "name = :name";
                $params['name'] = trim((string)$data['name']);
            }

            if (isset($data['start_date'])) {
                $fields[] = "start_date = :start_date";
                $params['start_date'] = $data['start_date'];
            }

            // Se vuoi permettere lo status, ok.
            if (isset($data['status'])) {
                $fields[] = "status = :status";
                $params['status'] = $data['status'];
            }

            if (empty($fields)) {
                throw new \Exception("Nessun campo da aggiornare");
            }

            $sql = "UPDATE tournaments
                    SET " . implode(", ", $fields) . ", updated_at = now()
                    WHERE id = :id
                    RETURNING *";

            $rows = DB::select($sql, $params);
            $updated = $rows[0] ?? null;
        });

        Response::success($updated, Response::HTTP_OK, "Torneo aggiornato")->send();
    } catch (\Exception $e) {
        Response::error('Errore aggiornamento torneo: ' . $e->getMessage(), Response::HTTP_BAD_REQUEST)->send();
    }
});


/**
 * DELETE /tournaments/{id} - elimina torneo (solo draft e senza risultati)
 */
//Converto id. Avvii una transaction perché farò 3 delete correlati.
//Dentro transaction tramite assertTournamentEditableOrDeletable → blocco completed o con risultati.
//cancelli match del torneo, cancelli partecipanti, cancelli il torneo poi fuori: ritorno OK.
//transaction necessaria perché: o cancelli tutto, o niente.

Router::delete('/tournaments/{id}', function ($id) {
    try {
        $tid = (int)$id;

        DB::transaction(function () use ($tid) {
            // blocca SOLO se completed o con risultati
            assertTournamentEditableOrDeletable($tid);

            // elimina match 
            DB::delete(
                "DELETE FROM matches WHERE tournament_id = :id",
                ['id' => $tid]
            );

            // elimina partecipanti
            DB::delete(
                "DELETE FROM tournament_participants WHERE tournament_id = :id",
                ['id' => $tid]
            );

            // elimina torneo
            DB::delete(
                "DELETE FROM tournaments WHERE id = :id",
                ['id' => $tid]
            );
        });

        Response::success(null, Response::HTTP_OK, "Torneo eliminato")->send();
    } catch (\Exception $e) {
        Response::error(
            'Errore eliminazione torneo: ' . $e->getMessage(),
            Response::HTTP_BAD_REQUEST
        )->send();
    }
});
