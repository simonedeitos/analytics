<?php

declare(strict_types=1);

// Eseguire con: php analyticspro/tests/test_gml_index_steps.php
$tmp = sys_get_temp_dir() . '/gml_steps_' . bin2hex(random_bytes(6));
define('ANALYTICSPRO_ROOT', $tmp);
require_once dirname(__DIR__) . '/includes/gml_catalog.php';
require_once dirname(__DIR__) . '/includes/gml_index_worker_logic.php';

function step_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function new_job(PDO $pdo, int $id, string $code): void
{
    $pdo->prepare("INSERT INTO gml_index_jobs (id, belfiore, status) VALUES (?, ?, 'queued')")
        ->execute([$id, $code]);
}

register_shutdown_function(static function () use ($tmp): void {
    if (is_dir($tmp)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($tmp);
    }
});

analyticspro_gml_ensure_dirs();
$fixture = dirname(__DIR__) . '/tests/fixtures/B394_test_ple.gml';
copy($fixture, analyticspro_gml_dir() . '/B394_Test_ple.gml');

// Stesse query del worker, con funzioni MySQL emulate solo nel database di test.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$locked = false;
$pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('GET_LOCK', static function () use (&$locked): int {
    if ($locked) {
        return 0;
    }
    $locked = true;
    return 1;
});
$pdo->sqliteCreateFunction('RELEASE_LOCK', static function () use (&$locked): int {
    $locked = false;
    return 1;
});
$pdo->exec("CREATE TABLE gml_index_jobs (
    id INTEGER PRIMARY KEY, belfiore TEXT, status TEXT, comune_codes TEXT,
    parcel_state TEXT, failed_comuni INTEGER DEFAULT 0, committed_particelle INTEGER DEFAULT 0,
    processed_comuni INTEGER DEFAULT 0, total_comuni INTEGER DEFAULT 0,
    processed_particelle INTEGER DEFAULT 0, total_particelle INTEGER DEFAULT 0,
    started_at TEXT, completed_at TEXT, error_message TEXT
)");
$pdo->exec('CREATE TABLE gml_index_job_log (id INTEGER PRIMARY KEY AUTOINCREMENT, job_id INTEGER, level TEXT, message TEXT)');

new_job($pdo, 1, 'B394');
$locked = true;
$busy = analyticspro_gml_index_step($pdo, 1);
step_assert(!empty($busy['busy']) && $busy['job']['status'] === 'queued', 'Lock occupato: nessun lavoro duplicato');
$locked = false;

// Budget zero: una sola feature per richiesta, con ripresa dal byte successivo.
$first = analyticspro_gml_index_step($pdo, 1, 0);
step_assert(!$first['done'] && $first['job']['status'] === 'running', 'queued -> running');
step_assert((int) $first['job']['processed_particelle'] === 1, 'Primo blocco persistito');
step_assert((int) $first['job']['total_particelle'] === 3, 'Totale particelle da intestazione GML');
step_assert(!analyticspro_gml_parcel_index_valid('B394'), 'Indice parziale non pubblicato');
$snapshot = $first['job']['comune_codes'];
copy($fixture, analyticspro_gml_dir() . '/C123_New_ple.gml');
$second = analyticspro_gml_index_step($pdo, 1, 0);
step_assert((int) $second['job']['processed_particelle'] === 2, 'Ripresa senza duplicati');
step_assert($second['job']['comune_codes'] === $snapshot, 'Snapshot comuni stabile');
for ($i = 0; $i < 5; $i++) {
    $result = analyticspro_gml_index_step($pdo, 1, 0);
    if ($result['done']) {
        break;
    }
}
step_assert($result['done'] && $result['job']['status'] === 'completed', 'running -> completed');
step_assert((int) $result['job']['processed_comuni'] === 1 && (int) $result['job']['processed_particelle'] === 3, 'Contatori finali esatti');
step_assert(analyticspro_gml_parcel_index_valid('B394'), 'Flag complete e dati SQLite persistiti');
$db = new SQLite3(analyticspro_gml_index_dir() . '/B394.sqlite', SQLITE3_OPEN_READONLY);
step_assert($db->querySingle('SELECT COUNT(*) FROM parcels') === 3, 'Ultimo blocco SQLite committed');
$db->close();
$logCount = (int) $pdo->query('SELECT COUNT(*) FROM gml_index_job_log WHERE job_id = 1')->fetchColumn();
analyticspro_gml_index_step($pdo, 1);
step_assert((int) $pdo->query('SELECT COUNT(*) FROM gml_index_job_log WHERE job_id = 1')->fetchColumn() === $logCount, 'Step terminale idempotente');

// Un comune senza particelle non blocca quelli successivi.
copy($fixture, analyticspro_gml_dir() . '/A001_Missing_map.gml');
new_job($pdo, 2, 'ALL');
$result = analyticspro_gml_index_step($pdo, 2);
step_assert(!$result['done'] && (int) $result['job']['processed_comuni'] === 1, 'Comune fallito conteggiato');
step_assert((int) $result['job']['failed_comuni'] === 1, 'Errore persistente tra richieste');
for ($i = 0; $i < 5 && !$result['done']; $i++) {
    $result = analyticspro_gml_index_step($pdo, 2, INF);
}
step_assert($result['done'] && $result['job']['status'] === 'failed', 'Errori isolati e stato finale failed');
step_assert((int) $result['job']['processed_comuni'] === 3 && (int) $result['job']['processed_particelle'] === 6, 'Gli altri comuni completano con contatori corretti');
step_assert($result['job']['error_message'] !== null && !$locked, 'Errore visibile e lock rilasciato');

// Perdita del temporaneo (es. crash dopo publish): ripartenza sicura, non indice tronco.
new_job($pdo, 3, 'B394');
analyticspro_gml_index_step($pdo, 3, 0);
unlink(analyticspro_gml_index_dir() . '/B394.job-3.sqlite');
$result = analyticspro_gml_index_step($pdo, 3, INF);
step_assert($result['done'] && (int) $result['job']['processed_particelle'] === 3, 'Temporaneo mancante: ricostruzione completa');

// Due job sullo stesso comune mantengono checkpoint e file indipendenti.
new_job($pdo, 4, 'B394');
new_job($pdo, 5, 'B394');
analyticspro_gml_index_step($pdo, 4, 0);
analyticspro_gml_index_step($pdo, 5, 0);
$four = analyticspro_gml_index_step($pdo, 4, INF);
$five = analyticspro_gml_index_step($pdo, 5, INF);
step_assert($four['done'] && $five['done'], 'Job sovrapposti completati');
step_assert((int) $four['job']['processed_particelle'] === 3 && (int) $five['job']['processed_particelle'] === 3, 'Checkpoint indipendenti per comune condiviso');

// Il cursore deve restare corretto anche attraversando il confine dei chunk da 1 MB.
$largePath = $tmp . '/large.gml';
file_put_contents($largePath, str_repeat(' ', ANALYTICSPRO_GML_CHUNK_SIZE - 150) . file_get_contents($fixture));
$offset = 0;
$refs = [];
for ($i = 0; $i < 4; $i++) {
    analyticspro_gml_stream_parcels($largePath, static function (array $feature) use (&$refs): bool {
        $refs[] = $feature['ref'];
        return true;
    }, $offset);
}
step_assert(count($refs) === 3 && count(array_unique($refs)) === 3, 'Ripresa al confine del buffer senza perdere o duplicare feature');

// API storica del costruttore senza checkpoint, usata anche dagli altri chiamanti.
step_assert(analyticspro_gml_build_parcel_index('B394') === 3, 'Compatibilità costruttore sincrono');
step_assert(analyticspro_gml_parcel_index_valid('B394'), 'Metadati sincroni persistiti');
echo "PASS: GML step HTTP/CLI, checkpoint, contatori, errori, lock e commit SQLite\n";
