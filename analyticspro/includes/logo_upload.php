<?php

declare(strict_types=1);

function analyticspro_validate_logo_png(string $path, string $name): void
{
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'png') {
        throw new RuntimeException('Il logo deve essere un file PNG.');
    }

    $size = is_file($path) ? filesize($path) : false;
    if ($size === false || $size === 0 || $size > 2 * 1024 * 1024) {
        throw new RuntimeException('Il logo deve essere un PNG di massimo 2 MB.');
    }

    $image = @getimagesize($path);
    $signature = file_get_contents($path, false, null, 0, 8);
    if ($signature !== "\x89PNG\r\n\x1a\n"
        || $image === false
        || ($image[2] ?? null) !== IMAGETYPE_PNG
        || (new finfo(FILEINFO_MIME_TYPE))->file($path) !== 'image/png') {
        throw new RuntimeException('Il file caricato non è un PNG valido.');
    }
}

function analyticspro_save_uploaded_logo(array $file): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Caricamento non riuscito. Seleziona un PNG di massimo 2 MB.');
    }

    $path = $file['tmp_name'] ?? null;
    $name = $file['name'] ?? null;
    if (!is_string($path) || !is_string($name) || !is_uploaded_file($path)) {
        throw new RuntimeException('Caricamento del logo non valido.');
    }

    analyticspro_validate_logo_png($path, $name);
    $destination = ANALYTICSPRO_ROOT . '/logo.png';
    if (is_link($destination) || !move_uploaded_file($path, $destination)) {
        throw new RuntimeException('Impossibile salvare il logo. Verifica i permessi della cartella della webapp.');
    }
}
