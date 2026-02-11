<?php

namespace App\Database;

/**
 * Classe DB per gestione database JSON-based
 */
class JSONDB
{

    const DB_FILE = __DIR__ . '/../../db.json';

    public static function read(?string $collection = null, ?string $filename = null)
    {
        if(empty($filename)) {
            $filename = self::DB_FILE;
        }
        if (!file_exists($filename)) {
            return [];
        }
        $content = file_get_contents($filename);
        $data = json_decode($content, true) ?? [];
        if($collection) {
            return $data[$collection] ?? [];
        }
        return $data;
    }

    public static function write(string $collection, array $data)
    {
        // Sanitizza i dati prima di salvarli
        $data = self::sanitize($data);
        
        // Legge tutte le collection esistenti
        $allData = self::read();
        // Aggiorna solo la collection specificata
        $allData[$collection] = $data;
        // Scrive tutto il file aggiornato
        return file_put_contents(self::DB_FILE, json_encode($allData, JSON_PRETTY_PRINT));
    }

    /**
     * Sanitizza i dati passati come array
     * Rimuove caratteri di controllo e normalizza le stringhe
     * 
     * @param array $data Dati da sanitizzare
     * @return array Dati sanitizzati
     */
    private static function sanitize(array $data): array
    {
        $sanitizedData = [];
        foreach($data as $key => $value) {
            // Gestisce valori nulli
            if ($value === null) {
                $sanitizedData[$key] = null;
                continue;
            }
            
            // Sanitizza solo le stringhe, lascia invariati gli altri tipi
            switch (gettype($value)) {
                case 'string':
                    $sanitizedData[$key] = self::sanitizeString($value);
                    break;
                case 'array':
                    // Ricorsivamente sanitizza gli array annidati
                    $sanitizedData[$key] = self::sanitize($value);
                    break;
                default:
                    // Mantiene invariati numeri, booleani e altri tipi
                    $sanitizedData[$key] = $value;
                    break;
            }
        }
        return $sanitizedData;
    }

    /**
     * Sanitizza una stringa
     * 
     * @param string $value Stringa da sanitizzare
     * @return string Stringa sanitizzata
     */
    private static function sanitizeString(string $value): string
    {
        // Trim degli spazi
        $value = trim($value);
        
        // Rimuove caratteri di controllo (0x00-0x1F) eccetto tab, newline, carriage return
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        
        // Normalizza spazi multipli in singoli spazi
        $value = preg_replace('/\s+/', ' ', $value);
        
        return $value;
    }

    public static function getNextId(string $collection)
    {
        $data = self::read($collection);
        if(empty($data)) {
            return 1;
        }
        return max(array_map(function($item) {
            return $item['id'];
        }, $data)) + 1;
    }
}
