<?php
use Pecee\SimpleRouter\SimpleRouter as Router;
use App\Utils\Response;

/**
 * File principale delle route
 * Carica tutte le route dai file separati per ogni risorsa
 */

// Route di benvenuto
Router::get('/', function() {
    Response::success([
        'message' => 'Benvenuto nella REST API',
        'version' => '1.0.0',
    ])->send();
});

// Route group per API
Router::group(['prefix' => '/api'], function() {
    // Carica automaticamente tutte le route dalla directory routes/
    // Esclude index.php per evitare loop infiniti
    $routeFiles = glob(__DIR__ . '/*.php');
    foreach ($routeFiles as $file) {
        $filename = basename($file);
        // Carica tutti i file PHP tranne index.php
        if ($filename !== 'index.php') {
            require $file;
        }
    }
});

Router::error(function() {
    Response::error('Endpoint non trovato', Response::HTTP_NOT_FOUND)->send();
});
