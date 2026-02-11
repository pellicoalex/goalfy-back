<?php

namespace App\Utils;

/**
 * Classe Response per gestire le risposte HTTP in formato JSON
 * Implementa il pattern Factory Method per creare risposte standardizzate
 */
class Response
{
    // Costanti per MIME types
    public const MIME_JSON = 'application/json';
    public const MIME_XML = 'application/xml';
    public const MIME_TEXT = 'text/plain';

    // Costanti per status codes HTTP comuni
    public const HTTP_OK = 200;
    public const HTTP_CREATED = 201;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_METHOD_NOT_ALLOWED = 405;
    public const HTTP_INTERNAL_SERVER_ERROR = 500;

    private string $mimeType;
    private int $statusCode;
    private array $data;
    private array $headers = [];
    private static ?array $corsConfig = null;

    /**
     * Costruttore privato per forzare l'uso dei metodi factory
     */
    private function __construct(string $mimeType, int $statusCode, array $data)
    {
        $this->mimeType = $mimeType;
        $this->statusCode = $statusCode;
        $this->data = $data;
        
        // Applica CORS automaticamente se configurato
        $this->applyCorsIfEnabled();
    }

    /**
     * Carica la configurazione CORS dal file di configurazione
     */
    private static function loadCorsConfig(): array
    {
        if (self::$corsConfig === null) {
            $configPath = __DIR__ . '/../../config/cors.php';
            self::$corsConfig = file_exists($configPath) ? require $configPath : ['enabled' => false];
        }
        return self::$corsConfig;
    }

    /**
     * Applica CORS automaticamente se abilitato nella configurazione
     */
    private function applyCorsIfEnabled(): void
    {
        $config = self::loadCorsConfig();
        
        if (!($config['enabled'] ?? false)) {
            return;
        }

        // Determina l'origine da permettere
        $allowedOrigins = $config['allowed_origins'] ?? ['*'];
        $allowCredentials = $config['allow_credentials'] ?? false;
        
        // Se allow_credentials è true, non possiamo usare '*' come origine
        if ($allowCredentials && in_array('*', $allowedOrigins)) {
            // Rimuovi '*' dall'array e usa solo le origini specifiche
            $allowedOrigins = array_filter($allowedOrigins, fn($origin) => $origin !== '*');
        }
        
        $origin = $this->getAllowedOrigin($allowedOrigins);
        
        // Gestisce gli header permessi (se contiene '*', usa direttamente '*')
        $allowedHeaders = $config['allowed_headers'] ?? ['*'];
        $allowedHeadersValue = in_array('*', $allowedHeaders) ? '*' : implode(', ', $allowedHeaders);
        
        // Applica gli header CORS
        $corsHeaders = [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => implode(', ', $config['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']),
            'Access-Control-Allow-Headers' => $allowedHeadersValue
        ];
        
        // Header esposti
        if (!empty($config['exposed_headers'])) {
            $corsHeaders['Access-Control-Expose-Headers'] = implode(', ', $config['exposed_headers']);
        }
        
        // Credenziali (solo se l'origine non è '*')
        if ($allowCredentials && $origin !== '*') {
            $corsHeaders['Access-Control-Allow-Credentials'] = 'true';
        }
        
        // Max age per preflight
        if (isset($config['max_age'])) {
            $corsHeaders['Access-Control-Max-Age'] = (string)$config['max_age'];
        }
        
        $this->setHeaders($corsHeaders);
    }

    /**
     * Determina l'origine permessa basandosi sulla richiesta corrente
     */
    private function getAllowedOrigin(array $allowedOrigins): string
    {
        // Se '*' è permesso, restituisci '*'
        if (in_array('*', $allowedOrigins)) {
            return '*';
        }

        // Ottieni l'origine dalla richiesta
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;

        if ($requestOrigin === null) {
            return $allowedOrigins[0] ?? '*';
        }

        // Verifica se l'origine della richiesta è permessa
        if (in_array($requestOrigin, $allowedOrigins)) {
            return $requestOrigin;
        }

        // Se non corrisponde, restituisci la prima origine permessa
        return $allowedOrigins[0] ?? '*';
    }

    /**
     * Imposta uno o più header alla risposta
     * 
     * @param array $headers Array associativo di header (nome => valore)
     *                       Per un singolo header: setHeaders(['Header-Name' => 'value'])
     * @return self
     */
    public function setHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    /**
     * Imposta gli header CORS
     */
    public function withCors(
        string $origin = '*',
        array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        array $allowedHeaders = ['*']
    ): self {
        return $this->setHeaders([
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => implode(', ', $allowedMethods),
            'Access-Control-Allow-Headers' => implode(', ', $allowedHeaders)
        ]);
    }

    /**
     * Invia la risposta JSON al client e termina l'esecuzione
     */
    public function send(): void
    {
        // Imposta gli header HTTP
        $this->applyHeaders();
        
        // Imposta lo status code
        http_response_code($this->statusCode);

        // Codifica i dati in JSON
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Verifica errori di encoding
        if ($json === false) {
            $this->handleJsonEncodeError();
            return;
        }

        // Invia la risposta
        echo $json;
        exit();
    }

    /**
     * Applica tutti gli header HTTP al client
     */
    private function applyHeaders(): void
    {
        // Imposta il Content-Type
        header('Content-Type: ' . $this->mimeType);

        // Imposta gli header personalizzati
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
    }

    /**
     * Gestisce gli errori di encoding JSON
     */
    private function handleJsonEncodeError(): void
    {
        http_response_code(self::HTTP_INTERNAL_SERVER_ERROR);
        header('Content-Type: ' . self::MIME_JSON);
        
        echo json_encode([
            'success' => false,
            'message' => 'Errore durante la codifica della risposta JSON',
            'error' => json_last_error_msg(),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        exit();
    }

    /**
     * Factory method per creare una risposta di errore
     * 
     * @param string $message Messaggio di errore
     * @param int $statusCode Codice di stato HTTP (default: 500)
     * @param array $errors Array di errori dettagliati (opzionale)
     * @return self
     */
    public static function error(
        string $message,
        int $statusCode = self::HTTP_INTERNAL_SERVER_ERROR,
        array $errors = []
    ): self {
        $data = [
            'success' => false,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Aggiungi gli errori solo se presenti
        if (!empty($errors)) {
            $data['errors'] = $errors;
        }

        return new self(self::MIME_JSON, $statusCode, $data);
    }

    /**
     * Factory method per creare una risposta di successo
     * 
     * @param mixed $data Dati da restituire
     * @param int $statusCode Codice di stato HTTP (default: 200)
     * @param string|null $message Messaggio opzionale
     * @return self
     */
    public static function success(
        $data,
        int $statusCode = self::HTTP_OK,
        ?string $message = null
    ): self {
        $responseData = [
            'success' => true,
            'data' => self::serializeData($data),
            'timestamp' => date('Y-m-d H:i:s'),
            ...(!empty($message) ? ['message' => $message] : [])
        ];

        return self::create($responseData, $statusCode);
    }

    /**
     * Factory method per creare una risposta personalizzata
     * 
     * @param array $data Dati da restituire
     * @param int $statusCode Codice di stato HTTP
     * @param string $mimeType Tipo MIME (default: application/json)
     * @return self
     */
    public static function create(
        array $data,
        int $statusCode = self::HTTP_OK,
        string $mimeType = self::MIME_JSON
    ): self {
        return new self($mimeType, $statusCode, self::serializeData($data));
    }

    /**
     * Gestisce le richieste preflight OPTIONS per CORS
     * Da chiamare nel bootstrap prima delle route
     */
    public static function handlePreflight(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
            return;
        }

        $config = self::loadCorsConfig();
        
        if (!($config['enabled'] ?? false)) {
            return;
        }

        // Crea una risposta vuota che applicherà automaticamente CORS
        self::create([], self::HTTP_OK)->send();
    }

    public static function serializeData($data)
    {
        if(is_array($data)) {
            if(!empty($data[0]) && is_object($data[0]) && $data[0] instanceof \App\Models\BaseModel) {
                $data = array_map(function($item) {
                    return $item->toArray();
                }, $data);
            }
        }
        if(is_object($data)) {
            if($data instanceof \App\Models\BaseModel) {
                $data = $data->toArray();
            }
        }
        return $data;
    }
}
