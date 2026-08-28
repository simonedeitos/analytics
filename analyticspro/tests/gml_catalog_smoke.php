<?php

declare(strict_types=1);

/**
 * Smoke test per il catalogo GML: lookup con varianti foglio, normalizzazione
 * particella, comune non indicizzato e fallback streaming.
 *
 * Eseguire con:  php analyticspro/tests/gml_catalog_smoke.php
 * Exit code 0 = OK, 1 = FAIL.
 */

if (!defined('ANALYTICSPRO_ROOT')) {
    define('ANALYTICSPRO_ROOT', dirname(__DIR__));
}
if (!function_exists('analyticspro_db')) {
    function analyticspro_db(): never
    {
        throw new RuntimeException('analyticspro_db() non disponibile in modalità test');
    }
}

require_once dirname(__DIR__) . '/includes/gml_stream_parser.php';
require_once dirname(__DIR__) . '/includes/geo_centroid.php';
require_once dirname(__DIR__) . '/includes/gml_catalog.php';

$tmpDir = sys_get_temp_dir() . '/gml_catalog_test_' . getmypid();
@mkdir($tmpDir, 0775, true);
register_shutdown_function(static function () use ($tmpDir): void {
    foreach (glob($tmpDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmpDir);
});

function cat_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// Costruisce un indice SQLite in memoria con dati di test
// ---------------------------------------------------------------------------
function build_test_index(string $dbPath, array $rows): void
{
    $db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS parcels (
        cod_foglio      TEXT NOT NULL,
        particella      TEXT NOT NULL,
        particella_norm TEXT NOT NULL,
        lat             REAL NOT NULL,
        lon             REAL NOT NULL,
        area_mq         REAL NOT NULL DEFAULT 0,
        PRIMARY KEY (cod_foglio, particella)
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_norm ON parcels(cod_foglio, particella_norm)');
    $db->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)');
    $db->exec("INSERT OR REPLACE INTO meta VALUES ('complete', '1')");
    $stmt = $db->prepare('INSERT OR REPLACE INTO parcels VALUES (:cf, :p, :pn, :lat, :lon, :a)');
    foreach ($rows as $r) {
        $stmt->bindValue(':cf',  $r[0], SQLITE3_TEXT);
        $stmt->bindValue(':p',   $r[1], SQLITE3_TEXT);
        $stmt->bindValue(':pn',  analyticspro_gml_norm_particella($r[1]), SQLITE3_TEXT);
        $stmt->bindValue(':lat', $r[2], SQLITE3_FLOAT);
        $stmt->bindValue(':lon', $r[3], SQLITE3_FLOAT);
        $stmt->bindValue(':a',   $r[4] ?? 0.0, SQLITE3_FLOAT);
        $stmt->execute();
        $stmt->reset();
    }
    $db->close();
}

// ============================================================
// 1. Lookup foglio esatto — caso base
// ============================================================
{
    $dbPath = $tmpDir . '/TST1.sqlite';
    build_test_index($dbPath, [
        ['003300', '147', 45.5, 10.5, 1000.0],
    ]);
    $result = analyticspro_gml_lookup_in_index($dbPath, '003300', '147', '147');
    cat_assert($result !== null,                                      'T1: lookup esatto deve trovare la particella');
    cat_assert(abs($result['lat'] - 45.5) < 0.001,                   'T1: lat deve essere 45.5');
    cat_assert($result['cod_foglio'] === '003300',                    'T1: cod_foglio deve essere 003300');
    echo "OK  T1: lookup foglio esatto\n";
}

// ============================================================
// 2. Lookup particella con zero-padding ("0147" → "147")
// ============================================================
{
    $dbPath = $tmpDir . '/TST2.sqlite';
    build_test_index($dbPath, [
        ['003300', '147', 45.5, 10.5, 1000.0],
    ]);
    $result = analyticspro_gml_lookup_in_index($dbPath, '003300', '0147', analyticspro_gml_norm_particella('0147'));
    cat_assert($result !== null, 'T2: particella con zero-padding deve essere trovata tramite particella_norm');
    echo "OK  T2: normalizzazione particella (0147 → 147)\n";
}

// ============================================================
// 3. Lookup foglio con variante allegato/sviluppo
//    L'indice contiene "0033A0"; la ricerca è per foglio "33" → cod "003300"
//    Il fallback sui primi 4 caratteri deve agganciarsi a "0033A0"
// ============================================================
{
    $dbPath = $tmpDir . '/TST3.sqlite';
    build_test_index($dbPath, [
        ['0033A0', '147', 45.6, 10.6, 2000.0],
    ]);
    $result = analyticspro_gml_lookup_in_index($dbPath, '003300', '147', '147');
    cat_assert($result !== null,                   'T3: variante allegato deve essere trovata tramite fallback 4 caratteri');
    cat_assert($result['cod_foglio'] === '0033A0', 'T3: cod_foglio deve essere quello reale nell\'indice (0033A0)');
    cat_assert(abs($result['lat'] - 45.6) < 0.001,'T3: lat deve essere 45.6');
    echo "OK  T3: fallback foglio (variante allegato/sviluppo 003300→0033A0)\n";
}

// ============================================================
// 4. Lookup particella lettera: "147/A" normalizzata a "147A"
// ============================================================
{
    $dbPath = $tmpDir . '/TST4.sqlite';
    build_test_index($dbPath, [
        ['003300', '147A', 45.7, 10.7, 500.0],
    ]);
    // Cerca con stringa grezza "147/A"
    $normPart = analyticspro_gml_norm_particella('147/A');
    cat_assert($normPart === '147A', 'T4: normalizzazione 147/A deve dare 147A');
    $result = analyticspro_gml_lookup_in_index($dbPath, '003300', '147/A', $normPart);
    cat_assert($result !== null, 'T4: particella con slash deve essere trovata');
    echo "OK  T4: normalizzazione particella con lettera (147/A → 147A)\n";
}

// ============================================================
// 5. Comune non indicizzato: analyticspro_gml_lookup_streaming
//    deve restituire null senza lanciare eccezioni se il file
//    non esiste nel catalogo
// ============================================================
{
    // Override temporaneo di analyticspro_gml_build_catalog per restituire catalogo vuoto
    // Non possiamo fare vero override di funzioni, usiamo streaming con belfiore inesistente
    $thrown = false;
    $result = null;
    try {
        // Belfiore inventato — nessun file GML presente
        $result = analyticspro_gml_lookup_streaming('Z999', '003300', '147', '147');
    } catch (Throwable $e) {
        $thrown = true;
    }
    cat_assert(!$thrown, 'T5: lookup streaming su comune inesistente non deve lanciare eccezioni');
    cat_assert($result === null, 'T5: lookup streaming su comune inesistente deve restituire null');
    echo "OK  T5: comune non indicizzato → null senza eccezioni\n";
}

// ============================================================
// 6. Fallback streaming sul file di fixture B394_test_ple.gml
//    (particella 147 del foglio 33)
// ============================================================
{
    $fixture = dirname(__DIR__) . '/tests/fixtures/B394_test_ple.gml';
    if (!is_file($fixture)) {
        echo "SKIP T6: file fixture non trovato ($fixture)\n";
    } else {
        // Copia il fixture in una directory che simula storage/gml
        $tmpGml = $tmpDir . '/B394_CALCINATO_ple.gml';
        copy($fixture, $tmpGml);

        // Sovrascrivi la funzione catalogo con uno stub che punta a tmpDir
        // Non è possibile in PHP senza estensioni — usiamo invece
        // analyticspro_gml_stream_parcels direttamente
        $found = null;
        analyticspro_gml_stream_parcels($tmpGml, static function (array $f) use (&$found): bool {
            if ($f['codFoglio'] === '003300' && analyticspro_gml_norm_particella($f['particella']) === '147') {
                $found = $f;
                return true;
            }
            return false;
        });
        cat_assert($found !== null, 'T6: particella 147 foglio 003300 deve essere trovata nel file fixture');
        echo "OK  T6: streaming diretto su fixture B394_test_ple.gml\n";
    }
}

// ============================================================
// 7. analyticspro_gml_norm_particella — casi limite
// ============================================================
{
    $cases = [
        ['0147',   '147'],
        ['147',    '147'],
        ['0001',   '1'],
        ['0000',   '0'],
        ['147/A',  '147A'],
        [' 147 ',  '147'],
        ['147a',   '147A'],
        ['00147B', '147B'],
        ['ACQUA310', 'ACQUA310'],
    ];
    foreach ($cases as [$input, $expected]) {
        $got = analyticspro_gml_norm_particella($input);
        cat_assert($got === $expected, "T7: norm_particella('$input') atteso '$expected', ottenuto '$got'");
    }
    echo "OK  T7: normalizzazione particella — casi limite\n";
}

// ============================================================
// 8. Nessuna collisione tra prefisso alfabetico e particella numerica
// ============================================================
{
    $dbPath = $tmpDir . '/TST8.sqlite';
    build_test_index($dbPath, [
        ['003400', 'ACQUA310', 45.1, 10.1, 1.0],
        ['003400', '310',      45.2, 10.2, 1.0],
    ]);
    $alpha = analyticspro_gml_lookup_in_index($dbPath, '003400', 'ACQUA310', analyticspro_gml_norm_particella('ACQUA310'));
    $num   = analyticspro_gml_lookup_in_index($dbPath, '003400', '310', analyticspro_gml_norm_particella('310'));
    cat_assert($alpha !== null && abs($alpha['lat'] - 45.1) < 0.001, 'T8: ACQUA310 non deve collidere con 310');
    cat_assert($num !== null && abs($num['lat'] - 45.2) < 0.001, 'T8: 310 deve restare distinto da ACQUA310');
    echo "OK  T8: nessuna collisione ACQUA310/310\n";
}

echo "\nTutti i test GML catalog superati.\n";
exit(0);
