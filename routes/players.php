<?php

use App\Utils\Response;
use App\Utils\Request;
use App\Models\Player;
use App\Models\Team;
use App\Database\DB;
use Pecee\SimpleRouter\SimpleRouter as Router;
use App\Utils\UploadHelper;

/**
 * Campi ammessi per create/update player
 */
function playerAllowedFields(): array
{
    return [
        'team_id',
        'first_name',
        'last_name',
        'number',
        'avatar_url',
        'nationality',
        'role',
        'height_cm',
        'weight_kg',
        'birth_date',
    ];
}

/**
 * Filtra un array mantenendo solo le chiavi ammesse
 */
function onlyAllowed(array $data, array $allowed): array
{
    return array_intersect_key($data, array_flip($allowed));
}

/**
 *  Stats dinamiche giocatore 
 * - goals  = match_goal_events.scorer_player_id
 * - assists= match_goal_events.assist_player_id
 * - matches= DISTINCT match_player_participations.match_id
 */
function getPlayerStats(int $playerId): array
{
    //COALESCE appena scoperto serve solo a evitare NULL e garantirti sempre un numero (0).
    $row = DB::select(
        "SELECT
        COALESCE((
          SELECT COUNT(*)::int
          FROM match_goal_events ge
          JOIN matches m ON m.id = ge.match_id
          WHERE ge.scorer_player_id = :pid
            AND m.status = 'played'::match_status
        ), 0) AS goals,

        COALESCE((
          SELECT COUNT(*)::int
          FROM match_goal_events ge
          JOIN matches m ON m.id = ge.match_id
          WHERE ge.assist_player_id = :pid
            AND m.status = 'played'::match_status
        ), 0) AS assists,

        COALESCE((
          SELECT COUNT(DISTINCT mp.match_id)::int
          FROM match_player_participations mp
          JOIN matches m ON m.id = mp.match_id
          WHERE mp.player_id = :pid
            AND m.status = 'played'::match_status
        ), 0) AS matches",
        ['pid' => $playerId]
    );

    $r = $row[0] ?? ['goals' => 0, 'assists' => 0, 'matches' => 0];

    return [
        'matches' => (int)($r['matches'] ?? 0),
        'goals' => (int)($r['goals'] ?? 0),
        'assists' => (int)($r['assists'] ?? 0),
    ];
}



/**
 * GET /players - Lista players (opzionale filtro ?team_id=)
 */
//Se c’è team_id → Player::where('team_id', '=', ...)
//Se no → Player::all()

Router::get('/players', function () {
    try {
        $request = new Request();
        $teamId = $request->getParam('team_id', null);

        if ($teamId !== null) {
            $players = Player::where('team_id', '=', (int)$teamId);
            Response::success($players)->send();
            return;
        }

        $players = Player::all();
        Response::success($players)->send();
        return;
    } catch (\Exception $e) {
        Response::error(
            'Errore nel recupero lista players: ' . $e->getMessage(),
            Response::HTTP_INTERNAL_SERVER_ERROR
        )->send();
        return;
    }
});




/**
 * GET /players/{id}
 * Ritorna player + team + full_name + stats (goal/assist/presenze) query lunga ma almeno mi ritorno tutto 
 */
//Uso una query JOIN players + teams per tornare player + info team in un colpo solo
//Poi calcolofull_name
//Poi calcolo stats con getPlayerStats()

Router::get('/players/{id}', function ($id) {
    try {
        $pid = (int)$id;

        $rows = DB::select(
            "SELECT
                p.id,
                p.team_id,
                p.first_name,
                p.last_name,
                p.number,
                p.avatar_url,
                p.nationality,
                p.role,
                p.height_cm,
                p.weight_kg,
                p.birth_date,
                p.created_at,
                p.updated_at,

                t.id       AS team_id_join,
                t.name     AS team_name,
                t.logo_url AS team_logo_url
             FROM players p
             JOIN teams t ON t.id = p.team_id
             WHERE p.id = :id
               AND t.deleted_at IS NULL
             LIMIT 1",
            ['id' => $pid]
        );

        if (empty($rows)) {
            Response::error('Player non trovato', Response::HTTP_NOT_FOUND)->send();
            return;
        }

        $r = $rows[0];

        $first = $r['first_name'] ?? '';
        $last  = $r['last_name'] ?? '';
        $fullName = trim($first . ' ' . $last);

        // stats reali
        $stats = getPlayerStats($pid);

        $playerPayload = [
            'id' => (int)$r['id'],
            'team_id' => (int)$r['team_id'],
            'first_name' => $r['first_name'],
            'last_name' => $r['last_name'],
            'full_name' => $fullName,
            'number' => $r['number'] !== null ? (int)$r['number'] : null,
            'avatar_url' => $r['avatar_url'] ?? null,
            'nationality' => $r['nationality'] ?? null,
            'role' => $r['role'] ?? null,
            'height_cm' => $r['height_cm'] !== null ? (int)$r['height_cm'] : null,
            'weight_kg' => $r['weight_kg'] !== null ? (int)$r['weight_kg'] : null,
            'birth_date' => $r['birth_date'] ?? null,

            'created_at' => $r['created_at'] ?? null,
            'updated_at' => $r['updated_at'] ?? null,
            'stats' => $stats,
        ];

        Response::success([
            'player' => $playerPayload,
            'stats' => $stats,

            'team' => [
                'id' => (int)$r['team_id_join'],
                'name' => $r['team_name'],
                'logo_url' => $r['team_logo_url'] ?? null,
            ],
        ])->send();
        return;
    } catch (\Exception $e) {
        Response::error(
            'Errore nel recupero player: ' . $e->getMessage(),
            Response::HTTP_INTERNAL_SERVER_ERROR
        )->send();
        return;
    }
});





/**
 * POST /players - Crea player
 * body: { team_id, first_name, last_name, number?, avatar_url?, nationality?, role?, height_cm?, weight_kg?, birth_date? }
 */
//verificare team esistente e non eliminato
//controllare max 5 players con:

Router::post('/players', function () {
    try {
        $request = new Request();
        $data = $request->json() ?? [];
        $data = onlyAllowed($data, playerAllowedFields());

        $errors = Player::validate($data);
        if (!empty($errors)) {
            Response::error('Errore di validazione', Response::HTTP_BAD_REQUEST, $errors)->send();
            return;
        }

        $team = Team::find((int)($data['team_id'] ?? 0));
        if ($team === null || $team->deleted_at !== null) {
            Response::error('Team non valido o eliminato', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        // check max 5  Il trigger DB controllerà
        $countRow = DB::select(
            "SELECT COUNT(*)::int AS cnt FROM players WHERE team_id = :team_id",
            ['team_id' => (int)$data['team_id']]
        );
        $cnt = $countRow[0]['cnt'] ?? 0;

        if ($cnt >= 5) {
            Response::error('Una squadra può avere massimo 5 players', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        $player = Player::create($data);

        Response::success($player, Response::HTTP_CREATED, 'Player creato con successo')->send();
        return;
    } catch (\Exception $e) {
        Response::error(
            'Errore durante creazione player: ' . $e->getMessage(),
            Response::HTTP_INTERNAL_SERVER_ERROR
        )->send();
        return;
    }
});




/**
 * PUT|PATCH /players/{id} - Aggiorna player
 * body: campi parziali ammessi 
 */

//controlla che team esista e non eliminato
//se è diverso dal team attuale, fa count max 5 sul nuovo team con DB::select
//valida merge (player corrente + data)
//update: $player->update($data)

Router::match(['put', 'patch'], '/players/{id}', function ($id) {
    try {
        $request = new Request();
        $data = $request->json() ?? [];
        $data = onlyAllowed($data, playerAllowedFields());

        if (empty($data)) {
            Response::error('Nessun dato da aggiornare', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        $player = Player::find((int)$id);
        if ($player === null) {
            Response::error('Player non trovato', Response::HTTP_NOT_FOUND)->send();
            return;
        }

        // se cambia team_id, valida che esista 
        if (isset($data['team_id'])) {
            $team = Team::find((int)$data['team_id']);
            if ($team === null || $team->deleted_at !== null) {
                Response::error('Team non valido o eliminato', Response::HTTP_BAD_REQUEST)->send();
                return;
            }

            // se sta spostando team, controlla max 5 anche lì
            if ((int)$data['team_id'] !== (int)($player->team_id ?? 0)) {
                $countRow = DB::select(
                    "SELECT COUNT(*)::int AS cnt FROM players WHERE team_id = :team_id",
                    ['team_id' => (int)$data['team_id']]
                );
                $cnt = $countRow[0]['cnt'] ?? 0;

                if ($cnt >= 5) {
                    Response::error('Una squadra può avere massimo 5 players', Response::HTTP_BAD_REQUEST)->send();
                    return;
                }
            }
        }

        // validazione completa (merge)
        $errors = Player::validate(array_merge($player->toArray(), $data));
        if (!empty($errors)) {
            Response::error('Errore di validazione', Response::HTTP_BAD_REQUEST, $errors)->send();
            return;
        }

        $player->update($data);
        Response::success($player, Response::HTTP_OK, 'Player aggiornato con successo')->send();
        return;
    } catch (\Exception $e) {
        Response::error(
            'Errore durante aggiornamento player: ' . $e->getMessage(),
            Response::HTTP_INTERNAL_SERVER_ERROR
        )->send();
        return;
    }
});




/**
 * POST /players/{id}/avatar - Upload avatar giocatore
 * multipart/form-data: file=<img>
 */

//trova player
//valida upload
//salva immagine
//update avatar_url

Router::post('/players/{id}/avatar', function ($id) {
    try {
        $player = Player::find((int)$id);
        if ($player === null) {
            Response::error('Player non trovato', Response::HTTP_NOT_FOUND)->send();
            return;
        }

        $request = new Request();
        $file = $request->file('file');
        if (!$file) {
            Response::error('File mancante (campo "file")', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        $err = UploadHelper::validateImageUpload($file);
        if ($err) {
            Response::error($err, Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        $url = UploadHelper::saveImage($file, 'uploads/players', 'player_' . $id);

        $player->update(['avatar_url' => $url]);

        Response::success($player, Response::HTTP_OK, 'Avatar caricato con successo')->send();
        return;
    } catch (\Exception $e) {
        Response::error(
            'Errore upload avatar: ' . $e->getMessage(),
            Response::HTTP_INTERNAL_SERVER_ERROR
        )->send();
        return;
    }
});



/**
 * DELETE /players/{id}
 */
//semplicemente find + delete

Router::delete('/players/{id}', function ($id) {
    try {
        $player = Player::find((int)$id);
        if ($player === null) {
            Response::error('Player non trovato', Response::HTTP_NOT_FOUND)->send();
            return;
        }

        $player->delete();
        Response::success(null, Response::HTTP_OK, 'Player eliminato con successo')->send();
        return;
    } catch (\Exception $e) {
        Response::error(
            'Errore durante eliminazione player: ' . $e->getMessage(),
            Response::HTTP_INTERNAL_SERVER_ERROR
        )->send();
        return;
    }
});
