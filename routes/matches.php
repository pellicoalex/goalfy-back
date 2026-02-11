<?php

use App\Utils\Response;
use App\Utils\Request;
use App\Services\TournamentService;
use Pecee\SimpleRouter\SimpleRouter as Router;

/**
 * PUT/PATCH /matches/{id}/result
 */
//Leggo il body JSON tramite $data = $request->json() ?? [], 
//Estrarre i punteggi accettando due formati di chiave: scoreA oppure score_a, scoreB oppure score_b
//Valido che scoreA e scoreB esistano: se manca uno dei due → rispondi 400 (“obbligatori”)
//Normalizzo i punteggi a interi: $scoreA = (int)$scoreA; $scoreB = (int)$scoreB
//Leggo due array opzionali (supportando più nomi possibili dal frontend/postman): participations (presenze giocatori) goalEvents (eventi goal)
//Se non sono array → li forzo a []
//Sanifico le participations: ciclo ogni elemento, leggo player_id (o playerId) e team_id (o teamId), scarto record invalidi (id <= 0)
//produci un array “pulito” con chiavi standard: ['player_id' => ..., 'team_id' => ...].
//Sanifico i goalEvents: leggo scorer e team (supportando chiavi alternative), scarto se scorer/team invalidi, 
//Gestisco  assist opzionale: se è '', 0, '0', null → lo metti null (eviti FK su 0), se assist == scorer → lo annulli (vincolo logico)
//Gestisco minute: supporto stringhe tipo "12'" o "12 min", estraggo il numero con regex,default 0 e mai negativo
//Produco un array “pulito”:['scorer_player_id'=>..., 'assist_player_id'=>..., 'minute'=>..., 'team_id'=>...]
//Controllo di coerenza: se hai più goalEvents della somma scoreA+scoreB → 400 (serve a evitare payload incoerenti)
//Delego tutto al service: crei TournamentService, chiamo:setMatchResult(matchId, scoreA, scoreB, cleanParticipations, cleanGoalEvents)
//la route valida e normalizza, ma la logica DB vera sta nel service. Rispondo OK:
//Risultato salvato e avanzamento completato
//Gestione errori:
//catch PDOException per errorInfo,catch Exception generico.

Router::match(['put', 'patch'], '/matches/{id}/result', function ($id) {
    try {
        $request = new Request();
        $data = $request->json() ?? [];

        $scoreA = $data['scoreA'] ?? $data['score_a'] ?? null;
        $scoreB = $data['scoreB'] ?? $data['score_b'] ?? null;

        if ($scoreA === null || $scoreB === null) {
            Response::error('scoreA e scoreB sono obbligatori', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        $scoreA = (int)$scoreA;
        $scoreB = (int)$scoreB;

        // opzionali: presenze + goal events (per stats reali)
        $participations = $data['participations']
            ?? $data['player_participations']
            ?? $data['match_player_participations']
            ?? [];

        $goalEvents = $data['goalEvents']
            ?? $data['goal_events']
            ?? $data['goals']
            ?? $data['match_goal_events']
            ?? [];

        if (!is_array($participations)) $participations = [];
        if (!is_array($goalEvents)) $goalEvents = [];

        // --- SANITIZE partecipations ---
        $cleanParticipations = [];
        foreach ($participations as $p) {
            if (!is_array($p)) continue;

            $playerId = (int)($p['player_id'] ?? $p['playerId'] ?? 0);
            $teamId   = (int)($p['team_id'] ?? $p['teamId'] ?? 0);

            if ($playerId <= 0 || $teamId <= 0) continue;

            $cleanParticipations[] = [
                'player_id' => $playerId,
                'team_id'   => $teamId,
            ];
        }

        // --- SANITIZE goalEvents ---
        $cleanGoalEvents = [];
        foreach ($goalEvents as $e) {
            if (!is_array($e)) continue;

            $scorerId = (int)($e['scorer_player_id'] ?? $e['scorerPlayerId'] ?? $e['scorer_id'] ?? $e['scorerId'] ?? 0);
            $teamId   = (int)($e['team_id'] ?? $e['teamId'] ?? 0);

            if ($scorerId <= 0 || $teamId <= 0) continue;

            // assist opzionale: '' / 0 / '0' -> NULL (evita FK su 0)
            $assistRaw = $e['assist_player_id'] ?? $e['assistPlayerId'] ?? $e['assist_id'] ?? $e['assistId'] ?? null;
            if ($assistRaw === null || $assistRaw === '' || $assistRaw === 0 || $assistRaw === '0') {
                $assistId = null;
            } else {
                $assistId = (int)$assistRaw;
                if ($assistId <= 0) $assistId = null;
            }

            // vincolo assist != scorer
            if ($assistId !== null && $assistId === $scorerId) {
                $assistId = null;
            }

            // minute: default 0, supporta stringhe tipo "12'" / "12 min"
            $minuteRaw = $e['minute'] ?? $e['min'] ?? null;
            if (is_string($minuteRaw)) {
                preg_match('/\d+/', $minuteRaw, $m);
                $minuteRaw = $m[0] ?? null;
            }

            $minute = ($minuteRaw === null || $minuteRaw === '') ? 0 : (int)$minuteRaw;
            if ($minute < 0) $minute = 0;

            $cleanGoalEvents[] = [
                'scorer_player_id' => $scorerId,
                'assist_player_id' => $assistId,
                'minute'           => $minute,
                'team_id'          => $teamId,
            ];
        }

        // safe: goalEvents totali devono essere <= scoreA+scoreB
        if (!empty($cleanGoalEvents) && count($cleanGoalEvents) > ($scoreA + $scoreB)) {
            Response::error(
                'Troppe marcature: goalEvents (' . count($cleanGoalEvents) . ') > scoreA+scoreB (' . ($scoreA + $scoreB) . ')',
                Response::HTTP_BAD_REQUEST
            )->send();
            return;
        }

        $service = new TournamentService();
        $service->setMatchResult((int)$id, $scoreA, $scoreB, $cleanParticipations, $cleanGoalEvents);

        Response::success(null, Response::HTTP_OK, 'Risultato salvato e avanzamento completato')->send();
    } catch (\PDOException $e) {
        $info = $e->errorInfo ?? null;

        Response::error(
            'Errore salvataggio risultato: ' . $e->getMessage() . ($info ? ' | errorInfo=' . json_encode($info) : ''),
            Response::HTTP_BAD_REQUEST
        )->send();
    } catch (\Exception $e) {
        Response::error('Errore salvataggio risultato: ' . $e->getMessage(), Response::HTTP_BAD_REQUEST)->send();
    }
});



/**
 * GET /matches/{id}/goal-events
 *
 * Ritorna SEMPRE i goal events persistiti per il match (anche se played/locked).
 */
//Recupero la connessione PDO direttamente DB::connection() preparo una SELECT che: prende i goal events del match tramite join su players per dati scorer (nome/cognome/avatar)
//left join su assist player (può essere null)
//filtra WHERE ge.match_id = :mid
//ordina: prima per minute (null “in fondo” usando COALESCE(..., 9999)), poi per id
//Eseguo la query passando mid, FetchAll in array associativo , ritorno rows come risposta “Goal events”.
//catch exception → errore 400
//lettura pura nessuna transaction qui, solo SELECT.


Router::get('/matches/{id}/goal-events', function ($id) {
    try {
        $db = \App\Database\DB::connection();

        $stmt = $db->prepare("
        SELECT
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
        JOIN players sp ON sp.id = ge.scorer_player_id
        LEFT JOIN players ap ON ap.id = ge.assist_player_id
        WHERE ge.match_id = :mid
        ORDER BY COALESCE(ge.minute, 9999) ASC, ge.id ASC
        ");

        $stmt->execute(['mid' => (int)$id]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        //  per restituire entrambe le chiavi
        Response::success($rows, Response::HTTP_OK, 'Goal events')->send();
    } catch (\Exception $e) {
        Response::error('Errore lettura goal events: ' . $e->getMessage(), Response::HTTP_BAD_REQUEST)->send();
    }
});

//alternativa senza PDO diretto

/* Router::get('/matches/{id}/goal-events', function ($id) {
    try {
        $rows = \App\Database\DB::select("
            SELECT
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
            JOIN players sp ON sp.id = ge.scorer_player_id
            LEFT JOIN players ap ON ap.id = ge.assist_player_id
            WHERE ge.match_id = :mid
            ORDER BY COALESCE(ge.minute, 9999) ASC, ge.id ASC
        ", ['mid' => (int)$id]);

        Response::success($rows, Response::HTTP_OK, 'Goal events')->send();
    } catch (\Exception $e) {
        Response::error('Errore lettura goal events: ' . $e->getMessage(), Response::HTTP_BAD_REQUEST)->send();
    }
}); */