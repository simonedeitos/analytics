<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/gml_catalog.php';

analyticspro_api_guard();
analyticspro_api_require_auth();
if (!analyticspro_is_admin()) {
    analyticspro_json(['ok' => false, 'error' => 'Accesso negato.'], 403);
}

analyticspro_verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    analyticspro_json(['ok' => false, 'error' => 'Metodo non supportato.'], 405);
}

if (empty($_FILES['files'])) {
    analyticspro_json(['ok' => false, 'error' => 'Nessun file inviato.'], 400);
}

analyticspro_gml_ensure_dirs();
$gmlDir = analyticspro_gml_dir();

$names    = (array) ($_FILES['files']['name']     ?? []);
$tmpNames = (array) ($_FILES['files']['tmp_name'] ?? []);
$errors   = (array) ($_FILES['files']['error']    ?? []);
$sizes    = (array) ($_FILES['files']['size']      ?? []);

$saved    = [];
$skipped  = [];
$replaced = [];

foreach ($names as $i => $rawName) {
    $err = (int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $skipped[] = $rawName . ' (errore upload: ' . $err . ')';
        continue;
    }

    $tmpPath = (string) ($tmpNames[$i] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        $skipped[] = $rawName . ' (non è un file caricato)';
        continue;
    }

    $ext = strtolower(pathinfo((string) $rawName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['gml', 'zip'], true)) {
        $skipped[] = $rawName . ' (estensione non supportata)';
        continue;
    }

    if ($ext === 'zip') {
        // Estrai ZIP e salva i GML trovati ricorsivamente
        $zipResult = analyticspro_gml_extract_zip($tmpPath, $gmlDir);
        $saved    = array_merge($saved, $zipResult['saved']);
        $skipped  = array_merge($skipped, $zipResult['skipped']);
        $replaced = array_merge($replaced, $zipResult['replaced']);
        continue;
    }

    // Validazione nome file GML
    $safeName = analyticspro_gml_sanitize_filename((string) $rawName);
    if ($safeName === null) {
        $skipped[] = $rawName . ' (nome file non valido)';
        continue;
    }

    if (!preg_match('/^([A-Za-z][0-9]{3})_.+_(ple|map)\.gml$/i', $safeName)) {
        $skipped[] = $rawName . ' (non corrisponde al pattern BBBB_..._ple|map.gml)';
        continue;
    }

    // Validazione contenuto
    if (!analyticspro_gml_validate_content($tmpPath)) {
        $skipped[] = $rawName . ' (non contiene dati catastali GML)';
        continue;
    }

    $dest = $gmlDir . '/' . $safeName;
    $isNew = !is_file($dest);

    if (!move_uploaded_file($tmpPath, $dest)) {
        $skipped[] = $rawName . ' (impossibile salvare il file)';
        continue;
    }

    if ($isNew) {
        $saved[] = $safeName;
    } else {
        $replaced[] = $safeName;
        error_log('[gml_upload] File sostituito: ' . $safeName);
    }
}

// Rigenera il catalogo dopo l'upload
if ($saved !== [] || $replaced !== []) {
    analyticspro_gml_build_catalog(true);
}

analyticspro_json([
    'ok'       => true,
    'saved'    => $saved,
    'replaced' => $replaced,
    'skipped'  => $skipped,
]);

// ---------------------------------------------------------------------------

function analyticspro_gml_extract_zip(string $zipPath, string $gmlDir): array
{
    $saved    = [];
    $skipped  = [];
    $replaced = [];

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        $skipped[] = basename($zipPath) . ' (impossibile aprire il ZIP)';
        return ['saved' => $saved, 'skipped' => $skipped, 'replaced' => $replaced];
    }

    $tmpDir = sys_get_temp_dir() . '/gml_upload_' . bin2hex(random_bytes(8));
    mkdir($tmpDir, 0700, true);

    $zip->extractTo($tmpDir);
    $zip->close();

    // Scansione ricorsiva alla ricerca di file GML e ZIP annidati
    $queue = [$tmpDir];
    while ($queue !== []) {
        $dir = array_shift($queue);
        $dh  = opendir($dir);
        if ($dh === false) {
            continue;
        }
        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            if (is_dir($full)) {
                $queue[] = $full;
            } elseif (is_file($full)) {
                $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
                if ($ext === 'zip') {
                    $nested = analyticspro_gml_extract_zip($full, $gmlDir);
                    $saved    = array_merge($saved, $nested['saved']);
                    $skipped  = array_merge($skipped, $nested['skipped']);
                    $replaced = array_merge($replaced, $nested['replaced']);
                } elseif ($ext === 'gml') {
                    $safeName = analyticspro_gml_sanitize_filename($entry);
                    if ($safeName === null || !preg_match('/^([A-Za-z][0-9]{3})_.+_(ple|map)\.gml$/i', $safeName)) {
                        $skipped[] = $entry . ' (pattern non valido in ZIP)';
                        continue;
                    }
                    if (!analyticspro_gml_validate_content($full)) {
                        $skipped[] = $entry . ' (non contiene dati catastali GML)';
                        continue;
                    }
                    $dest  = $gmlDir . '/' . $safeName;
                    $isNew = !is_file($dest);
                    if (copy($full, $dest)) {
                        if ($isNew) {
                            $saved[] = $safeName;
                        } else {
                            $replaced[] = $safeName;
                            error_log('[gml_upload] File sostituito da ZIP: ' . $safeName);
                        }
                    } else {
                        $skipped[] = $entry . ' (impossibile salvare da ZIP)';
                    }
                }
            }
        }
        closedir($dh);
    }

    // Pulizia directory temporanea
    analyticspro_gml_rmdir_recursive($tmpDir);

    return ['saved' => $saved, 'skipped' => $skipped, 'replaced' => $replaced];
}

function analyticspro_gml_rmdir_recursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            analyticspro_gml_rmdir_recursive($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
