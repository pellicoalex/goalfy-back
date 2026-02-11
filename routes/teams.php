<?php

use App\Utils\Response;
use App\Utils\Request;
use App\Models\Team;
use App\Database\DB;
use Pecee\SimpleRouter\SimpleRouter as Router;
use App\Utils\UploadHelper;

/**
 * GET /teams - Lista team 
 */
//lista squadre.
//dal DB tutti i team non soft-deleted WHERE deleted_at IS NULL
//Ritorno l’array di righe al client con Response::success.
//Perché DB::select e non Team::all(), perché vuoi filtro + ordinamento e il Model non ha orderBy/whereNull.

Router::get('/teams', function () {
    try {
        $teams = DB::select("
            SELECT *
            FROM teams
            WHERE deleted_at IS NULL
            ORDER BY created_at DESC, id DESC
        ");

        Response::success($teams)->send();
        return;
    } catch (\Exception $e) {
        Response::error(
            'Errore nel recupero lista team: ' . $e->getMessage(),
            Response::HTTP_INTERNAL_SERVER_ERROR
        )->send();
        return;
    }
});




/**
 * GET /teams/ready - Lista team "pronti" (5 players) 
 */

//team pronti”.
//query che prende i team t li collega ai players p (JOIN players p ON p.team_id = t.id)
//come sopra escludo i team soft-deleted (t.deleted_at IS NULL).
//Raggruppi per team (GROUP BY t.id) per poter contare i players, filtro i team che hanno esattamente 5 players (HAVING COUNT(p.id) = 5)
//Perché è aggregazione (COUNT + HAVING) e il Model non supporta group/having.

Router::get('/teams/ready', function () {
    try {
        $teams = DB::select("
            SELECT t.*
            FROM teams t
            JOIN players p ON p.team_id = t.id
            WHERE t.deleted_at IS NULL
            GROUP BY t.id
            HAVING COUNT(p.id) = 5
            ORDER BY t.id DESC
        ");

        Response::success($teams)->send();
        return;
    } catch (\Exception $e) {
        Response::error(
            'Errore nel recupero lista team pronti: ' . $e->getMessage(),
            Response::HTTP_INTERNAL_SERVER_ERROR
        )->send();
        return;
    }
});





/**
 * GET /teams/{id} - Dettaglio team + players
 */

//Converto {id} in int, cercho il team con Team::find(id) se non esiste →ovviamente 404, bloccho i team soft-deleted (deleted_at != null → 404).
//$team->load('players') → mi aggiunge i players associati.
//Ritorno il team (con dentro players) come payload.
//è un’operazione “di dominio”: recuperare un team e le sue relazioni.(model no)

Router::get('/teams/{id}', function ($id) {
    try {
        $team = Team::find((int)$id);

        if ($team === null || $team->deleted_at !== null) {
            Response::error('Team non trovato', Response::HTTP_NOT_FOUND)->send();
            return;
        }
        //carica le relazioni
        $team->load('players');

        Response::success($team)->send();
        return;
    } catch (\Exception $e) {
        Response::error('Errore nel recupero team: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        return;
    }
});



/**
 * POST /teams - Crea team
 * body: { "name": "...", "logo_url"?: "..." }
 */

//Leggo il JSON body, valido i dati con Team::validate, se errori → 400 con dettagli, Creo il team con Team::create($data) (INSERT).
//Ritorno 201 Created con il team creato.
//un semplice INSERT su una tabella: perfetto per create().

Router::post('/teams', function () {
    try {
        $request = new Request();
        $data = $request->json();

        $errors = Team::validate($data);
        if (!empty($errors)) {
            Response::error('Errore di validazione', Response::HTTP_BAD_REQUEST, $errors)->send();
            return;
        }

        $team = Team::create($data);
        Response::success($team, Response::HTTP_CREATED, 'Team creato con successo')->send();
        return;
    } catch (\Exception $e) {
        Response::error('Errore durante la creazione team: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        return;
    }
});



/**
 * PUT|PATCH /teams/{id} - Aggiorna team
 * body: { "name"?: "...", "logo_url"?: "..." }
 */
//Leggo JSON body (campi da aggiornare),cerchi il team (Team::find), se non esiste / soft-deleted → 404, validazione “completa”.
//validate(array_merge(team attuale + nuovi dati)), così controllo anche vincoli su campi non passati.

//Aggiornamneto nel DB: $team->update($data), ritorno OK. merge+validate, perché se validassi solo $data potrei “saltare” regole che dipendono dall’insieme.

Router::match(['put', 'patch'], '/teams/{id}', function ($id) {
    try {
        $request = new Request();
        $data = $request->json();

        $team = Team::find((int)$id);
        if ($team === null || $team->deleted_at !== null) {
            Response::error('Team non trovato', Response::HTTP_NOT_FOUND)->send();
            return;
        }

        $errors = Team::validate(array_merge($team->toArray(), $data));
        if (!empty($errors)) {
            Response::error('Errore di validazione', Response::HTTP_BAD_REQUEST, $errors)->send();
            return;
        }

        $team->update($data);
        Response::success($team, Response::HTTP_OK, 'Team aggiornato con successo')->send();
        return;
    } catch (\Exception $e) {
        Response::error('Errore durante aggiornamento team: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        return;
    }
});



/**
 * POST /teams/{id}/logo - Upload logo squadra
 * multipart/form-data: file=<img>
 */
//Cercho il team e bloccho soft-deleted(come dappertutto), leggo file multipart file, validazione immagine (tipo, dimensione, ecc).
//Salvo il file su disco e ottieni URL, aggiorno la colonna logo_url, tramite $team->update(['logo_url' => $url]).
//Ritorno team aggiornato.
//DB::query Perché ho già l’oggetto team e update() è più pulito.

Router::post('/teams/{id}/logo', function ($id) {
    try {
        $team = Team::find((int)$id);
        if ($team === null || $team->deleted_at !== null) {
            Response::error('Team non trovato', Response::HTTP_NOT_FOUND)->send();
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

        $url = UploadHelper::saveImage($file, 'uploads/teams', 'team_' . $id);

        // salva nel DB 
        $team->update(['logo_url' => $url]);

        Response::success($team, Response::HTTP_OK, 'Logo caricato con successo')->send();
        return;
    } catch (\Exception $e) {
        Response::error('Errore upload logo: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        return;
    }
});



/**
 * GET /teams/{id}/players - Lista players del team
 * Utile per PlayersPanel del front / TeamCard senza scaricare tutto il team.
 */
//Verifico che il team esista e non sia eliminato, faccio una query che prende i players del team (WHERE team_id = :team_id)
//Seleziono solo le colonne utili, Ordino i players,prima quelli con number valorizzato, poi numero crescente, poi cognome/nome.
//Ritorno la lista, perché utilizzo DB::select e non Player::where, mi serve un ORDER BY e una select colonne specifiche;(Model no)

Router::get('/teams/{id}/players', function ($id) {
    try {
        $team = Team::find((int)$id);
        if ($team === null || $team->deleted_at !== null) {
            Response::error('Team non trovato', Response::HTTP_NOT_FOUND)->send();
            return;
        }

        $players = DB::select(
            "SELECT
                id,
                team_id,
                first_name,
                last_name,
                number,
                avatar_url,
                nationality,
                role,
                height_cm,
                weight_kg,
                birth_date,
                created_at,
                updated_at
             FROM players
             WHERE team_id = :team_id
             ORDER BY
                number IS NULL,
                number ASC,
                last_name ASC,
                first_name ASC",
            ['team_id' => (int)$id]
        );

        Response::success($players)->send();
        return;
    } catch (\Exception $e) {
        Response::error('Errore nel recupero players del team: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        return;
    }
});



/**
 * DELETE /teams/{id}
 * - usa delete_team(id) 
 */
//Non faccio delete() del model, chiamo una funzione DB:delete_team(:id), poi l DB si occupa di: soft delete del team.
//Ritorno OK, perché non utilizzo DB::statement,perché non mi interessa un result set, voglio solo eseguire lo statement.

Router::delete('/teams/{id}', function ($id) {
    try {
        DB::statement("SELECT delete_team(:id)", ['id' => (int)$id]);
        Response::success(null, Response::HTTP_OK, 'Team eliminato (soft delete)')->send();
        return;
    } catch (\Exception $e) {
        Response::error('Impossibile eliminare team: ' . $e->getMessage(), Response::HTTP_BAD_REQUEST)->send();
        return;
    }
});
