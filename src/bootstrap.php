<?php
/**
 * Bootstrap dell'applicazione
 * Questo file carica l'autoloader, configura l'ambiente e avvia il router
 */

// Carica l'autoloader di Composer
require __DIR__ . '/../vendor/autoload.php';

use Pecee\SimpleRouter\SimpleRouter;
use App\Utils\Response;

// Configurazione ambiente base
date_default_timezone_set('Europe/Rome');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// Configurazione error reporting (suppress deprecation warnings)
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

// Gestisce le richieste preflight OPTIONS per CORS
Response::handlePreflight();

// Error handler globale per catturare errori fatali e TypeError
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

// Exception handler globale per catturare tutte le eccezioni non gestite
set_exception_handler(function($exception) {
    Response::error(
        'Errore interno del server: ' . $exception->getMessage(),
        Response::HTTP_INTERNAL_SERVER_ERROR
    )->send();
});

try {
    // Carica le routes
    require __DIR__ . '/../routes/index.php';

    // Avvia il router
    SimpleRouter::start();
} catch (\Throwable $e) {
    // Cattura TypeError, Error, Exception e tutte le eccezioni
    // Se è un TypeError durante l'inizializzazione, potrebbe essere dovuto a JSON non valido
    // che SimpleRouter cerca di parsare prima che le route vengano eseguite
    $message = $e->getMessage();
    
    if ($e instanceof \TypeError && $_SERVER['REQUEST_METHOD'] !== 'GET') {
        // Verifica se il body contiene JSON non valido
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            json_decode($input);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // La validazione JSON è già gestita in Request::json(), 
                // ma qui catturiamo errori che avvengono durante l'inizializzazione del router
                Response::error(
                    'JSON non valido nel body della richiesta: ' . json_last_error_msg(),
                    Response::HTTP_BAD_REQUEST
                )->send();
                return;
            }
        }
    }
    
    Response::error(
        'Errore durante l\'inizializzazione: ' . $message,
        Response::HTTP_INTERNAL_SERVER_ERROR
    )->send();
}


