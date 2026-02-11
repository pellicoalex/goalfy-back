<?php

namespace App\Utils;

class UploadHelper
{
    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        //controllo se la cartella non esiste
        //la creo: 0777 → permessi massimi
        //true → creo anche cartelle annidate (uploads/players)
        //serve a non rompere l’upload se la directory non esiste ancora.
    }

    public static function validateImageUpload(array $file): ?string //controllo validità file uplodato
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { //controlla errore PHP upload
            return 'Upload fallito (codice errore: ' . ($file['error'] ?? 'unknown') . ')';
        }

        $maxBytes = 3 * 1024 * 1024; //limite messo a 3MB (se serve si può espandere) messo per l'upload delle immagini in teams e avatar palyers
        if (($file['size'] ?? 0) > $maxBytes) {
            return 'Immagine troppo grande (max 3MB)';
        }

        $tmp = $file['tmp_name'] ?? null;
        if (!$tmp || !is_uploaded_file($tmp)) {
            return 'File temporaneo non valido';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE); //estensioni accettate si posso aumentare volendo
        $mime = $finfo->file($tmp);

        $allowed = [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];

        if (!isset($allowed[$mime])) {
            return 'Formato non supportato. Usa png/jpg/webp.';
        }

        return null;  //se tutto ok return null
    }

    public static function saveImage( //salvataggio vero e proprio
        array $file,
        string $folderPublic,
        string $prefix
    ): string {
        $tmp = $file['tmp_name'];

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);

        $extMap = [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];
        $ext = $extMap[$mime] ?? 'bin';

        $publicRoot = realpath(__DIR__ . '/../../public'); //trovo la cartella public
        //Prende un percorso relativo e lo trasforma in un percorso assoluto reale
        //Risolve .., ., symlink(symbolic link non è un file o cartella punta a un altro percorso ),po ritorna false se il path non esiste.
        $targetDir = $publicRoot . '/' . trim($folderPublic, '/'); //costruisco la cartella

        self::ensureDir($targetDir);

        $filename = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext; //genero il nome del file  
        //random_bytes(8) → genera 8 byte casuali sicuri
        //bin2hex() → li converte in 16 caratteri leggibili
        //Risultato: stringa unica, imprevedibile
        //Serve per creare nomi file sicuri senza collisioni.
        $targetPath = $targetDir . '/' . $filename;

        if (!move_uploaded_file($tmp, $targetPath)) {
            throw new \Exception('Impossibile salvare il file su disco');
        }

        return '/' . trim($folderPublic, '/') . '/' . $filename;
    }
}
