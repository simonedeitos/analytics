<?php

declare(strict_types=1);

/**
 * Pagina di manutenzione manuale per storage/gml.
 *
 * Funzioni:
 *  - Normalizza i nomi dei file .gml in storage/gml sostituendo gli spazi con "_".
 *    Se dopo la normalizzazione si genera un conflitto con un file già esistente,
 *    il file con spazi nel nome viene eliminato (si tiene quello senza conflitto).
 *  - Rigenera il catalogo comuni (storage/gml_index/catalogo.json).
 *  - Genera/aggiorna i conteggi catalogo (storage/gml_index/catalogo_counts.json).
 *
 * Pagina pensata per essere chiamata a mano da un amministratore (link
 * "Maintenance" nel menu amministrazione): nessuna chiamata AJAX, solo form
 * POST classici e log minimale.
 */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/layout.php';
require ANALYTICSPRO_ROOT . '/admin/_admin_check.php';
require_once ANALYTICSPRO_ROOT . '/includes/gml_catalog.php';

/**
 * Scansiona storage/gml, sostituisce gli spazi nei nomi file con "_".
 * In caso di conflitto con un file già esistente (stesso nome dopo la
 * normalizzazione), il file con spazi viene cancellato.
 *
 * @return array<int,string> righe di log
 */
function analyticspro_gml_normalize_filenames(): array
{
    $log = [];
    $gmlDir = analyticspro_gml_dir();

    if (!is_dir($gmlDir)) {
        $log[] = "Cartella storage/gml non trovata: $gmlDir";
        return $log;
    }

    $files = glob($gmlDir . '/*.gml') ?: [];
    $renamed = 0;
    $deleted = 0;
    $skipped = 0;

    foreach ($files as $path) {
        $basename = basename($path);

        if (!str_contains($basename, ' ')) {
            continue;
        }

        $newBasename = str_replace(' ', '_', $basename);
        $newPath = $gmlDir . '/' . $newBasename;

        if (is_file($newPath)) {
            // Conflitto: esiste già un file con il nome normalizzato.
            // Si elimina quello con gli spazi.
            if (@unlink($path)) {
                $deleted++;
                $log[] = "CONFLITTO: '$basename' eliminato perché '$newBasename' esiste già.";
            } else {
                $skipped++;
                $log[] = "ERRORE: impossibile eliminare '$basename' (conflitto con '$newBasename').";
            }
            continue;
        }

        if (@rename($path, $newPath)) {
            $renamed++;
            $log[] = "Rinominato: '$basename' -> '$newBasename'.";
        } else {
            $skipped++;
            $log[] = "ERRORE: impossibile rinominare '$basename'.";
        }
    }

    $log[] = "Riepilogo: $renamed rinominati, $deleted eliminati per conflitto, $skipped errori.";

    return $log;
}

$log = [];
$action = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    analyticspro_verify_csrf($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'normalize') {
        $log = analyticspro_gml_normalize_filenames();
    } elseif ($action === 'rebuild_catalog') {
        $catalog = analyticspro_gml_build_catalog(true);
        $log[] = 'Catalogo comuni rigenerato: ' . count($catalog) . ' comuni trovati.';
    } elseif ($action === 'normalize_and_rebuild') {
        $log = analyticspro_gml_normalize_filenames();
        $catalog = analyticspro_gml_build_catalog(true);
        $log[] = 'Catalogo comuni rigenerato: ' . count($catalog) . ' comuni trovati.';
    } elseif ($action === 'rebuild_counts') {
        @set_time_limit(300);
        $counts = analyticspro_gml_rebuild_counts_cache();
        $log[] = 'Conteggi catalogo generati: ' . $counts['count'] . ' comuni, generato il '
            . date('d/m/Y H:i:s', $counts['generated_at']) . '.';
    } else {
        $log[] = 'Azione non riconosciuta.';
    }
}

$countsCache = analyticspro_gml_load_counts_cache();

analyticspro_render_header('Manutenzione GML');
require ANALYTICSPRO_ROOT . '/admin/_admin_subnav.php';
?>
<div class="container py-4" style="max-width:700px">
    <h1 class="h4 mb-4">Manutenzione file GML</h1>

    <form method="post" class="mb-2">
        <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
        <input type="hidden" name="action" value="normalize">
        <button type="submit" class="btn btn-outline-secondary btn-sm">
            Normalizza nomi file (spazi → _)
        </button>
    </form>

    <form method="post" class="mb-2">
        <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
        <input type="hidden" name="action" value="rebuild_catalog">
        <button type="submit" class="btn btn-outline-secondary btn-sm">
            Rigenera catalogo comuni
        </button>
    </form>

    <form method="post" class="mb-4">
        <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
        <input type="hidden" name="action" value="normalize_and_rebuild">
        <button type="submit" class="btn btn-primary btn-sm">
            Normalizza nomi file + rigenera catalogo
        </button>
    </form>

    <h2 class="h6 mb-2">Conteggi catalogo (particelle / fogli)</h2>
    <p class="small text-muted mb-2">
        La pagina Import GML legge il numero di particelle e fogli di ogni comune dal file
        <code>catalogo_counts.json</code>, senza aprire gli indici SQLite. Rigenerare i conteggi
        dopo aver indicizzato, aggiunto o rimosso comuni.
    </p>
    <?php if ($countsCache !== null): ?>
        <p class="small mb-2">
            Ultima generazione: <?= analyticspro_h(date('d/m/Y H:i:s', $countsCache['generated_at'])) ?>
            (<?= (int) $countsCache['count'] ?> comuni).
        </p>
    <?php else: ?>
        <div class="alert alert-warning small py-2 mb-2">
            Il file dei conteggi non esiste ancora: in Import GML particelle e fogli risulteranno a 0.
        </div>
    <?php endif; ?>

    <form method="post" class="mb-4">
        <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
        <input type="hidden" name="action" value="rebuild_counts">
        <button type="submit" class="btn btn-primary btn-sm">
            Genera/aggiorna conteggi
        </button>
    </form>

    <?php if ($log !== []): ?>
        <div class="border rounded p-2 bg-light small" style="font-family:monospace;white-space:pre-wrap">
<?php foreach ($log as $line): ?>
<?= analyticspro_h($line) ?>

<?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php analyticspro_render_footer(); ?>
