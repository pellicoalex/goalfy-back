<?php

namespace App\Utils;

/**
 * Classe Request per gestire le richieste HTTP
 * Wrappa le superglobali PHP e fornisce metodi utili per accedere ai dati della richiesta
 * Può essere istanziata e passata come parametro nelle closure delle rotte
 */
class Request
{

    /**
     * Metodo HTTP della richiesta
     * 
     * @return string
     */
    public function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * Verifica se il metodo è GET
     * 
     * @return bool
     */
    public function isGet(): bool
    {
        return $this->method() === 'GET';
    }

    /**
     * Verifica se il metodo è POST
     * 
     * @return bool
     */
    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /**
     * Verifica se il metodo è PUT
     * 
     * @return bool
     */
    public function isPut(): bool
    {
        return $this->method() === 'PUT';
    }

    /**
     * Verifica se il metodo è PATCH
     * 
     * @return bool
     */
    public function isPatch(): bool
    {
        return $this->method() === 'PATCH';
    }

    /**
     * Verifica se il metodo è DELETE
     * 
     * @return bool
     */
    public function isDelete(): bool
    {
        return $this->method() === 'DELETE';
    }

    /**
     * Ottiene tutti i parametri GET
     * 
     * @return array
     */
    public function get(): array
    {
        return $_GET ?? [];
    }

    /**
     * Ottiene un parametro GET specifico
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getParam(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * Ottiene tutti i parametri POST
     * 
     * @return array
     */
    public function post(): array
    {
        return $_POST ?? [];
    }

    /**
     * Ottiene un parametro POST specifico
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function postParam(string $key, $default = null)
    {
        return $_POST[$key] ?? $default;
    }

    /**
     * Ottiene tutti i dati della richiesta (GET + POST)
     * 
     * @return array
     */
    public function all(): array
    {
        return array_merge($this->get(), $this->post());
    }

    /**
     * Ottiene un parametro dalla richiesta (cerca prima in GET, poi in POST)
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        if (isset($_GET[$key])) {
            return $_GET[$key];
        }
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }
        return $default;
    }

    /**
     * Ottiene il body JSON della richiesta
     * 
     * @return array
     * @throws \Exception Se il JSON non è valido
     */
    public function json(): array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('JSON non valido: ' . json_last_error_msg());
        }
        
        return $data ?? [];
    }

    /**
     * Ottiene un header HTTP specifico
     * 
     * @param string $name Nome dell'header (case-insensitive)
     * @param mixed $default
     * @return mixed
     */
    public function header(string $name, $default = null)
    {
        $name = strtolower($name);
        $headers = $this->headers();
        
        // Normalizza il nome dell'header (HTTP_ prefix viene rimosso e convertito)
        $normalizedName = str_replace('-', '_', $name);
        
        foreach ($headers as $key => $value) {
            if (strtolower(str_replace('-', '_', $key)) === $normalizedName) {
                return $value;
            }
        }
        
        return $default;
    }

    /**
     * Ottiene tutti gli header HTTP
     * 
     * @return array
     */
    public function headers(): array
    {
        $headers = [];
        
        // Funzione per ottenere gli header
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
        } else {
            // Fallback per server che non supportano apache_request_headers
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $header = str_replace('_', '-', substr($key, 5));
                    $headers[$header] = $value;
                }
            }
        }
        
        return $headers;
    }

    /**
     * Ottiene l'URI della richiesta
     * 
     * @return string
     */
    public function uri(): string
    {
        return $_SERVER['REQUEST_URI'] ?? '/';
    }

    /**
     * Ottiene il path della richiesta (senza query string)
     * 
     * @return string
     */
    public function path(): string
    {
        $uri = parse_url($this->uri(), PHP_URL_PATH);
        return $uri ?? '/';
    }

    /**
     * Ottiene la query string
     * 
     * @return string
     */
    public function queryString(): string
    {
        return $_SERVER['QUERY_STRING'] ?? '';
    }

    /**
     * Ottiene l'IP del client
     * 
     * @return string
     */
    public function ip(): string
    {
        $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Se ci sono più IP (proxy chain), prendi il primo
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                return trim($ip);
            }
        }
        
        return '0.0.0.0';
    }

    /**
     * Ottiene l'user agent
     * 
     * @return string
     */
    public function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * Ottiene il content type della richiesta
     * 
     * @return string
     */
    public function contentType(): string
    {
        return $_SERVER['CONTENT_TYPE'] ?? '';
    }

    /**
     * Verifica se la richiesta è AJAX
     * 
     * @return bool
     */
    public function isAjax(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Verifica se la richiesta è JSON
     * 
     * @return bool
     */
    public function isJson(): bool
    {
        return strpos($this->contentType(), 'application/json') !== false;
    }

    /**
     * Ottiene tutti i file caricati
     * 
     * @return array
     */
    public function files(): array
    {
        return $_FILES ?? [];
    }

    /**
     * Ottiene un file specifico
     * 
     * @param string $key
     * @return array|null
     */
    public function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }
}

