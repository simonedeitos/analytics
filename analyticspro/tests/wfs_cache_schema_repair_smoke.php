<?php

declare(strict_types=1);

/**
 * Test: apertura cache SQLite con schema obsoleto (senza colonna `source`)
 * auto-ripara senza eccezioni.
 *
 * Exit code: 0 pass, 1 fail.
 */

require_once dirname(__DIR__) . '/includes/wfs_lookup.php';

$dbPath = dirname(__DIR__, 2) . '/cache/catasto/catasto_cache.db';
$dbDir = dirname($dbPath);
$backupPath = $dbPath . '.bak_test_' . getmypid();

if (!is_dir($dbDir) && !mkdir($dbDir, 0775, true) && !is_dir($dbDir)) {
    fwrite(STDERR, "FAIL: impossibile creare directory cache\n");
    exit(1);
}

if (is_file($dbPath)) {
    if (!copy($dbPath, $backupPath)) {
        fwrite(STDERR, "FAIL: impossibile fare backup cache esistente\n");
        exit(1);
    }
    @unlink($dbPath);
}

register_shutdown_function(static function () use ($dbPath, $backupPath): void {
    @unlink($dbPath);
    if (is_file($backupPath)) {
        @copy($backupPath, $dbPath);
        @unlink($backupPath);
    }
});

$legacy = new SQLite3($dbPath);
$legacy->exec('CREATE TABLE particelle_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cod_catastale TEXT NOT NULL,
    foglio TEXT NOT NULL,
    particella TEXT NOT NULL,
    lat REAL NOT NULL,
    lng REAL NOT NULL,
    area_mq REAL,
    cached_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(cod_catastale, foglio, particella)
)');
$legacy->close();

try {
    $db = analyticspro_wfs_open_cache_db();
    $info = $db->query('PRAGMA table_info(particelle_cache)');
    $hasSource = false;
    while (($row = $info->fetchArray(SQLITE3_ASSOC)) !== false) {
        if (strcasecmp((string) ($row['name'] ?? ''), 'source') === 0) {
            $hasSource = true;
            break;
        }
    }
    $db->close();
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: eccezione inattesa: {$e->getMessage()}\n");
    exit(1);
}

if (!$hasSource) {
    fwrite(STDERR, "FAIL: colonna source non aggiunta automaticamente\n");
    exit(1);
}

echo "PASS: cache SQLite auto-riparata (colonna source presente)\n";
exit(0);
