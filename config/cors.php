<?php

/**
 * Configurazione CORS (Cross-Origin Resource Sharing)
 * 
 * Questa configurazione viene applicata automaticamente a tutte le risposte
 * se 'enabled' è true.
 */

return [
    // Abilita/disabilita CORS automatico
    'enabled' => true,

    // Origini permesse (array di domini o '*' per tutti)
    // In produzione, specifica i domini esatti invece di '*'
    'allowed_origins' => ['*'], // Esempio: ['https://mia-app.com', 'https://www.mia-app.com']

    // Metodi HTTP permessi
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],

    // Header permessi nelle richieste
    'allowed_headers' => ['*'], // Esempio: ['Content-Type', 'Authorization', 'X-Requested-With']

    // Header esposti al client
    'exposed_headers' => [],

    // Permettere credenziali (cookies, auth headers)
    'allow_credentials' => false,

    // Cache preflight requests (in secondi)
    'max_age' => 86400, // 24 ore
];

