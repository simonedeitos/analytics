<?php

declare(strict_types=1);

/**
 * Catalogo e indici locali per i file GML ADE.
 *
 * Struttura delle directory:
 *   storage/gml/            → file GML appiattiti  (e .htaccess deny-all)
 *   storage/gml_index/      → indici JSON/SQLite    (e .htaccess deny-all)
 *     catalogo.json         → ['B394' => ['ple' => path, 'map' => path, 'nome' => ...]]
 *     {BELFIORE}_fogli.json → indice codici foglio dal _map.gml
 *     {BELFIORE}.sqlite     → indice particelle per lookup O(1)
 */

require_once __DIR__ . '/gml_stream_parser.php';
require_once __DIR__ . '/geo_centroid.php';

// ---------------------------------------------------------------------------
// Directory helper
// ---------------------------------------------------------------------------

function analyticspro_gml_dir(): string
{
    return ANALYTICSPRO_ROOT . '/storage/gml';
}

function analyticspro_gml_index_dir(): string
{
    return ANALYTICSPRO_ROOT . '/storage/gml_index';
}

// ---------------------------------------------------------------------------
// Catalogo
// ---------------------------------------------------------------------------

/**
 * Ricostruisce (o legge dalla cache) il catalogo dei file GML.
 *
 * Il catalogo è un array indicizzato per codice Belfiore:
 *   ['B394' => [
 *       'ple'      => '/path/B394_..._ple.gml',
 *       'map'      => '/path/B394_..._map.gml',
 *       'nome'     => 'Calcinato',
 *       'size_ple' => 12345678,
 *       'size_map' => 234567,
 *       'mtime'    => 1700000000,
 *   ]]
 *
 * @return array<string,array>
 */
function analyticspro_gml_build_catalog(bool $force = false): array
{
    $indexDir  = analyticspro_gml_index_dir();
    $gmlDir    = analyticspro_gml_dir();
    $cachePath = $indexDir . '/catalogo.json';
    $reversePath = analyticspro_gml_reverse_index_path();

    analyticspro_gml_ensure_dirs();

    if (!$force && is_file($cachePath)) {
        $raw = @file_get_contents($cachePath);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    $catalog = [];

    if (!is_dir($gmlDir)) {
        return $catalog;
    }

    $files = glob($gmlDir . '/*.gml') ?: [];
    foreach ($files as $path) {
        $basename = basename($path);
        // Pattern: BBBB_something_(ple|map).gml
        if (!preg_match('/^([A-Za-z][0-9]{3})_(.+?)_(ple|map)\.gml$/i', $basename, $m)) {
            continue;
        }

        $belfiore = strtoupper($m[1]);
        $type     = strtolower($m[3]); // 'ple' or 'map'

        if (!isset($catalog[$belfiore])) {
            // Estrae il nome direttamente dal nome file (sorgente primaria, sempre disponibile)
            $nomeFromFile = ucwords(mb_strtolower(str_replace('_', ' ', $m[2])));
            // Fallback DB se il nome dal file è vuoto
            if ($nomeFromFile === '') {
                $nomeFromFile = analyticspro_gml_nome_comune($belfiore);
            }
            $catalog[$belfiore] = [
                'ple'      => null,
                'map'      => null,
                'nome'     => $nomeFromFile,
                'size_ple' => 0,
                'size_map' => 0,
                'mtime'    => 0,
            ];
        }

        $stat = stat($path);
        $size = $stat !== false ? (int) $stat['size'] : 0;
        $mtime = $stat !== false ? (int) $stat['mtime'] : 0;

        $catalog[$belfiore][$type]         = $path;
        $catalog[$belfiore]['size_' . $type] = $size;
        if ($mtime > $catalog[$belfiore]['mtime']) {
            $catalog[$belfiore]['mtime'] = $mtime;
        }
    }

    ksort($catalog);
    file_put_contents($cachePath, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @unlink($reversePath);
    return $catalog;
}

function analyticspro_gml_reverse_index_path(): string
{
    return analyticspro_gml_index_dir() . '/comuni_reverse.json';
}

function analyticspro_gml_norm_provincia(string $provincia): string
{
    $provincia = strtoupper(trim($provincia));
    return preg_replace('/[^A-Z]/', '', $provincia) ?? '';
}

function analyticspro_gml_norm_nome_comune(string $comune): string
{
    $comune = strtoupper(trim($comune));
    $comune = str_replace(['_', '-'], ' ', $comune);
    $comune = strtr($comune, [
        'À' => 'A',
        'Á' => 'A',
        'Â' => 'A',
        'Ä' => 'A',
        'È' => 'E',
        'É' => 'E',
        'Ê' => 'E',
        'Ë' => 'E',
        'Ì' => 'I',
        'Í' => 'I',
        'Î' => 'I',
        'Ï' => 'I',
        'Ò' => 'O',
        'Ó' => 'O',
        'Ô' => 'O',
        'Ö' => 'O',
        'Ù' => 'U',
        'Ú' => 'U',
        'Û' => 'U',
        'Ü' => 'U',
    ]);
    $comune = preg_replace('/\bSS\.\s*/u', 'SANTI ', $comune) ?? $comune;
    $comune = preg_replace('/\bST\.\s*/u', 'SANTO ', $comune) ?? $comune;
    $comune = preg_replace('/\bS\.\s*/u', 'SAN ', $comune) ?? $comune;
    $comune = preg_replace('/\bS\s+(?=[A-Z])/u', 'SAN ', $comune) ?? $comune;
    $comune = preg_replace('/\bD\s*\/\s*/u', 'DELLE ', $comune) ?? $comune;
    $comune = str_replace(["'", '’', '`'], '', $comune);
    $comune = preg_replace('/[^A-Z0-9 ]+/u', ' ', $comune) ?? $comune;
    return trim(preg_replace('/\s+/u', ' ', $comune) ?? $comune);
}

/**
 * @return array{exact:array<string,array<int,string>>,compact:array<string,array<int,string>>}
 */
function analyticspro_gml_load_reverse_index(bool $force = false): array
{
    static $inMemory = null;
    static $inMemoryMtime = 0;

    $catalogPath = analyticspro_gml_index_dir() . '/catalogo.json';
    $cachePath   = analyticspro_gml_reverse_index_path();
    $version     = 1;

    if (!$force && is_array($inMemory) && is_file($catalogPath) && ((int) filemtime($catalogPath)) <= $inMemoryMtime) {
        return $inMemory;
    }

    analyticspro_gml_build_catalog($force);
    $catalogMtime = is_file($catalogPath) ? ((int) filemtime($catalogPath)) : 0;

    if (
        !$force
        && is_file($cachePath)
        && ((int) filemtime($cachePath)) >= $catalogMtime
    ) {
        $decoded = json_decode((string) @file_get_contents($cachePath), true);
        if (is_array($decoded) && (int) ($decoded['version'] ?? 0) === $version) {
            $inMemory = [
                'exact' => is_array($decoded['exact'] ?? null) ? $decoded['exact'] : [],
                'compact' => is_array($decoded['compact'] ?? null) ? $decoded['compact'] : [],
            ];
            $inMemoryMtime = (int) filemtime($cachePath);
            return $inMemory;
        }
    }

    $catalog = analyticspro_gml_build_catalog();
    $exact   = [];
    $compact = [];
    foreach ($catalog as $belfiore => $entry) {
        $nome = (string) ($entry['nome'] ?? '');
        $norm = analyticspro_gml_norm_nome_comune($nome);
        if ($norm === '') {
            continue;
        }
        $keys = [$norm, str_replace(' ', '', $norm)];
        foreach (array_unique($keys) as $key) {
            if ($key === '') {
                continue;
            }
            $bucket = str_contains($key, ' ') ? 'exact' : 'compact';
            if ($bucket === 'exact') {
                $target = &$exact;
            } else {
                $target = &$compact;
            }
            $target[$key] ??= [];
            if (!in_array($belfiore, $target[$key], true)) {
                $target[$key][] = $belfiore;
            }
            unset($target);
        }
    }

    ksort($exact);
    ksort($compact);
    $payload = ['version' => $version, 'exact' => $exact, 'compact' => $compact];
    file_put_contents($cachePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $inMemory = ['exact' => $exact, 'compact' => $compact];
    $inMemoryMtime = is_file($cachePath) ? ((int) filemtime($cachePath)) : time();
    return $inMemory;
}

/**
 * @return array{codes:array<int,string>,match:string}
 */
function analyticspro_gml_reverse_candidates(string $comune): array
{
    $norm    = analyticspro_gml_norm_nome_comune($comune);
    $compact = str_replace(' ', '', $norm);
    if ($norm === '') {
        return ['codes' => [], 'match' => 'none'];
    }

    $index = analyticspro_gml_load_reverse_index();
    foreach ([['exact', $norm, 'exact'], ['compact', $compact, 'compact']] as [$bucket, $key, $match]) {
        if ($key !== '' && !empty($index[$bucket][$key])) {
            return ['codes' => array_values(array_unique($index[$bucket][$key])), 'match' => $match];
        }
    }

    $prefixMatches = [];
    foreach (['exact' => $norm, 'compact' => $compact] as $bucket => $prefix) {
        if ($prefix === '') {
            continue;
        }
        foreach ($index[$bucket] as $key => $codes) {
            if (str_starts_with($key, $prefix)) {
                $prefixMatches = array_merge($prefixMatches, $codes);
            }
        }
    }

    return ['codes' => array_values(array_unique($prefixMatches)), 'match' => 'prefix'];
}

/**
 * @return array<string,array<int,string>>
 */
function analyticspro_gml_belfiore_province_map(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }

    $map = [];
    $candidates = [
        __DIR__ . '/../../data/comuni_catastali.json',
        __DIR__ . '/../../../data/comuni_catastali.json',
        dirname(ANALYTICSPRO_ROOT) . '/data/comuni_catastali.json',
    ];

    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        $rows = json_decode((string) file_get_contents($candidate), true);
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper(trim((string) ($row['codice_catastale'] ?? '')));
            $prov = analyticspro_gml_norm_provincia((string) ($row['sigla_provincia'] ?? ''));
            if ($code === '' || $prov === '') {
                continue;
            }
            $map[$code] ??= [];
            if (!in_array($prov, $map[$code], true)) {
                $map[$code][] = $prov;
            }
        }
        break;
    }

    if (function_exists('analyticspro_db')) {
        try {
            $stmt = analyticspro_db()->query('SELECT cod_catastale, provincia_sigla FROM cadastral_comuni');
            if ($stmt !== false) {
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $code = strtoupper(trim((string) ($row['cod_catastale'] ?? '')));
                    $prov = analyticspro_gml_norm_provincia((string) ($row['provincia_sigla'] ?? ''));
                    if ($code === '' || $prov === '') {
                        continue;
                    }
                    $map[$code] ??= [];
                    if (!in_array($prov, $map[$code], true)) {
                        $map[$code][] = $prov;
                    }
                }
            }
        } catch (Throwable) {
        }
    }

    return $map;
}

function analyticspro_gml_describe_candidates(array $codes): string
{
    $catalog     = analyticspro_gml_build_catalog();
    $provinceMap = analyticspro_gml_belfiore_province_map();
    $parts       = [];
    foreach ($codes as $code) {
        $name = (string) ($catalog[$code]['nome'] ?? '');
        $prov = implode('/', $provinceMap[$code] ?? []);
        $parts[] = trim($code . ($name !== '' ? ' ' . $name : '') . ($prov !== '' ? ' [' . $prov . ']' : ''));
    }
    return implode(', ', $parts);
}

/**
 * Risolve il codice Belfiore a partire dal nome del comune, usando il catalogo
 * dei file GML locali come sorgente primaria. La provincia è opzionale e viene
 * usata solo per disambiguare eventuali omonimie.
 *
 * @return string|null
 */
function analyticspro_gml_belfiore_da_comune(string $comune, string $provincia = ''): ?string
{
    $result     = analyticspro_gml_reverse_candidates($comune);
    $candidates = $result['codes'];
    if ($candidates === []) {
        return null;
    }
    if (count($candidates) === 1) {
        return $candidates[0];
    }

    $provinciaNorm = analyticspro_gml_norm_provincia($provincia);
    if ($provinciaNorm !== '') {
        $provinceMap = analyticspro_gml_belfiore_province_map();
        $filtered    = [];
        foreach ($candidates as $candidate) {
            if (in_array($provinciaNorm, $provinceMap[$candidate] ?? [], true)) {
                $filtered[] = $candidate;
            }
        }
        if (count($filtered) === 1) {
            return $filtered[0];
        }
        if ($filtered !== []) {
            $candidates = $filtered;
        }
    }

    error_log('[gml_catalog] Ambiguità risoluzione comune "' . $comune . '"'
        . ($provincia !== '' ? ' provincia "' . $provincia . '"' : '')
        . ' → candidati: ' . analyticspro_gml_describe_candidates($candidates));
    return null;
}

/**
 * Costruisce l'indice dei codici foglio per un comune (dal _map.gml).
 * Cache in {BELFIORE}_fogli.json, invalidata se il GML è più recente.
 *
 * @return array<string,string>  codFoglio → label
 */
function analyticspro_gml_foglio_index(string $belfiore): array
{
    $belfiore  = strtoupper($belfiore);
    $indexDir  = analyticspro_gml_index_dir();
    $cachePath = $indexDir . '/' . $belfiore . '_fogli.json';

    $catalog = analyticspro_gml_build_catalog();
    $entry   = $catalog[$belfiore] ?? null;
    if ($entry === null || empty($entry['map'])) {
        return [];
    }

    $mapPath = $entry['map'];
    $gmlMtime = @filemtime($mapPath) ?: 0;

    if (is_file($cachePath) && filemtime($cachePath) >= $gmlMtime) {
        $raw = @file_get_contents($cachePath);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    $fogli = [];
    analyticspro_gml_stream_zonings($mapPath, static function (array $f) use (&$fogli): bool {
        if ($f['codFoglio'] !== '') {
            $fogli[$f['codFoglio']] = $f['label'];
        }
        return false;
    });

    file_put_contents($cachePath, json_encode($fogli, JSON_PRETTY_PRINT));
    return $fogli;
}

/**
 * Normalizza una particella catastale per il confronto:
 *   "0147"   → "147"
 *   "147/A"  → "147A"
 *   " 147 "  → "147"
 *   "147a"   → "147A"
 */
function analyticspro_gml_norm_particella(string $s): string
{
    $s = strtoupper(trim($s));
    $s = preg_replace('/[^A-Z0-9]+/', '', $s) ?? '';
    if ($s === '') {
        return '';
    }

    if (preg_match('/^([A-Z]+)?(\d+)([A-Z]*)$/', $s, $m)) {
        $prefix = $m[1] ?? '';
        $digits = ltrim($m[2], '0');
        $suffix = $m[3] ?? '';
        return $prefix . ($digits === '' ? '0' : $digits) . $suffix;
    }

    return $s;
}

// ---------------------------------------------------------------------------
// Indice particelle SQLite (O(1) lookup)
// ---------------------------------------------------------------------------

/**
 * Apre (creando se necessario) il database SQLite dell'indice particelle.
 * Se il DB esiste ma manca la colonna `particella_norm`, lo invalida (rimozione)
 * in modo che venga ricostruito dal worker.
 */
function analyticspro_gml_open_parcel_db(string $belfiore): SQLite3
{
    $belfiore = strtoupper($belfiore);
    analyticspro_gml_ensure_dirs();
    $dbPath = analyticspro_gml_index_dir() . '/' . $belfiore . '.sqlite';

    // Migrazione: se il DB esiste ma non ha particella_norm, lo invalida (cancella)
    // così verrà ricostruito con lo schema aggiornato.
    if (is_file($dbPath)) {
        try {
            $checkDb = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
            $res = $checkDb->query("PRAGMA table_info(parcels)");
            $hasNorm = false;
            while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC)) !== false) {
                if ($row['name'] === 'particella_norm') {
                    $hasNorm = true;
                    break;
                }
            }
            $checkDb->close();
            if (!$hasNorm) {
                // Invalida l'indice: rimuovi il file così sarà ricostruito
                @unlink($dbPath);
                error_log('[gml_catalog] Indice ' . $belfiore . ' invalidato: schema aggiornato (particella_norm mancante), ricostruzione necessaria.');
            }
        } catch (Throwable $e) {
            // Se non riusciamo ad aprirlo, lo rimuoviamo e ricominciamo
            @unlink($dbPath);
        }
    }

    $db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA synchronous=NORMAL');
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
    return $db;
}

/**
 * Costruisce l'indice particelle SQLite per un comune.
 * Esegue una singola scansione completa del _ple.gml.
 *
 * @param  callable(int,int):void|null  $progressCb  ($processed, $total)
 */
function analyticspro_gml_build_parcel_index(string $belfiore, ?callable $progressCb = null): int
{
    $belfiore = strtoupper($belfiore);
    $catalog  = analyticspro_gml_build_catalog();
    $entry    = $catalog[$belfiore] ?? null;
    if ($entry === null || empty($entry['ple'])) {
        throw new RuntimeException('File _ple.gml non trovato per il comune: ' . $belfiore);
    }

    $plePath = $entry['ple'];
    $db      = analyticspro_gml_open_parcel_db($belfiore);

    // Cancella il flag 'complete' prima di iniziare: rende l'indice non valido
    // finché la costruzione non è terminata (indicizzazione atomica).
    $db->exec("DELETE FROM meta WHERE key = 'complete'");

    $db->exec('BEGIN');
    $stmt = $db->prepare('INSERT OR REPLACE INTO parcels (cod_foglio, particella, particella_norm, lat, lon, area_mq)
                          VALUES (:cod_foglio, :particella, :particella_norm, :lat, :lon, :area_mq)');

    $count   = 0;
    $batchSz = 500;
    $buf     = [];

    $flush = static function () use ($db, $stmt, &$buf, &$count): void {
        foreach ($buf as $row) {
            $stmt->bindValue(':cod_foglio',     $row[0], SQLITE3_TEXT);
            $stmt->bindValue(':particella',     $row[1], SQLITE3_TEXT);
            $stmt->bindValue(':particella_norm', $row[2], SQLITE3_TEXT);
            $stmt->bindValue(':lat',             $row[3], SQLITE3_FLOAT);
            $stmt->bindValue(':lon',             $row[4], SQLITE3_FLOAT);
            $stmt->bindValue(':area_mq',         $row[5], SQLITE3_FLOAT);
            $stmt->execute();
            $count++;
        }
        $buf = [];
        $db->exec('COMMIT');
        $db->exec('BEGIN');
    };

    analyticspro_gml_stream_parcels($plePath, static function (array $f) use (&$buf, &$count, $batchSz, $flush, $progressCb): bool {
        if ($f['codFoglio'] === '' || $f['particella'] === '') {
            return false;
        }

        $extRings = $f['ext'];
        $intRings = $f['int'];
        if ($extRings === []) {
            return false;
        }

        $exterior = analyticspro_largest_ring($extRings) ?? $extRings[0];
        $pt       = analyticspro_interior_point($exterior, $intRings);
        if ($pt === null) {
            return false;
        }

        $areaMq = analyticspro_ring_area_m2($exterior);
        // Sottrai l'area dei ring interni (buchi) per una superficie netta corretta
        foreach ($intRings as $hole) {
            $areaMq -= analyticspro_ring_area_m2($hole);
        }
        if ($areaMq < 0) {
            $areaMq = 0.0;
        }

        $buf[] = [$f['codFoglio'], $f['particella'], analyticspro_gml_norm_particella($f['particella']), $pt['lat'], $pt['lng'], $areaMq];

        if (count($buf) >= $batchSz) {
            $flush();
            if ($progressCb !== null) {
                $progressCb($count, 0);
            }
        }
        return false;
    });

    $flush();

    // Aggiorna metadati
    $metaStmt = $db->prepare("INSERT OR REPLACE INTO meta (key,value) VALUES (:key, :value)");
    $metaStmt->bindValue(':key', 'indexed_at', SQLITE3_TEXT);
    $metaStmt->bindValue(':value', (string) time(), SQLITE3_TEXT);
    $metaStmt->execute();
    $metaStmt->bindValue(':key', 'count', SQLITE3_TEXT);
    $metaStmt->bindValue(':value', (string) $count, SQLITE3_TEXT);
    $metaStmt->execute();
    // Flag di completamento atomico: scritto SOLO a fine scansione riuscita.
    // analyticspro_gml_parcel_index_valid() verifica questo flag.
    $metaStmt->bindValue(':key', 'complete', SQLITE3_TEXT);
    $metaStmt->bindValue(':value', '1', SQLITE3_TEXT);
    $metaStmt->execute();

    if ($progressCb !== null) {
        $progressCb($count, $count);
    }

    return $count;
}

/**
 * Controlla se l'indice SQLite di un comune è valido:
 * - file esiste
 * - non è più vecchio del GML
 * - contiene il flag meta['complete'] = '1' (scrittura atomica riuscita)
 */
function analyticspro_gml_parcel_index_valid(string $belfiore): bool
{
    $belfiore  = strtoupper($belfiore);
    $dbPath    = analyticspro_gml_index_dir() . '/' . $belfiore . '.sqlite';
    if (!is_file($dbPath)) {
        return false;
    }

    $catalog = analyticspro_gml_build_catalog();
    $entry   = $catalog[$belfiore] ?? null;
    if ($entry === null || empty($entry['ple'])) {
        return false;
    }

    $gmlMtime = @filemtime($entry['ple']) ?: 0;
    $dbMtime  = @filemtime($dbPath) ?: 0;

    if ($dbMtime < $gmlMtime) {
        return false;
    }

    // Verifica flag di completamento atomico
    try {
        $db  = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
        $res = $db->query("SELECT value FROM meta WHERE key = 'complete' LIMIT 1");
        $complete = ($res !== false && ($row = $res->fetchArray(SQLITE3_NUM)) !== false)
            ? ($row[0] === '1')
            : false;
        $db->close();
        return $complete;
    } catch (Throwable $e) {
        return false;
    }
}

// ---------------------------------------------------------------------------
// Lookup principale
// ---------------------------------------------------------------------------

/**
 * Ricerca le coordinate di una particella catastale nel repository GML locale.
 *
 * Strategia:
 *   1. Indice SQLite (O(1)) con foglio esatto + particella esatta/normalizzata.
 *   2. Fallback foglio: se il cod_foglio esatto non è presente nell'indice,
 *      cerca righe con i primi 4 caratteri del foglio coincidenti (gestisce
 *      varianti allegato/sviluppo, es. "0033A0" quando si cerca "003300").
 *   3. Se l'indice non è valido ma esiste il file _ple.gml (e ha dimensione
 *      ragionevole), ricerca diretta in streaming con early-exit.
 *
 * @return array{lat:float,lon:float,area_mq:float,ref:string,local_id:string,cod_foglio:string}|null
 */
function analyticspro_gml_lookup(
    string $belfiore,
    string $foglio,
    string $particella,
    string $allegato = '',
    string $sviluppo = ''
): ?array {
    $belfiore = strtoupper(trim($belfiore));
    if (!preg_match('/^[A-Z][0-9]{3}$/', $belfiore)) {
        $belfiore = analyticspro_gml_belfiore_da_comune($belfiore) ?? '';
    }
    if ($belfiore === '') {
        return null;
    }
    $codFoglio = analyticspro_gml_codice_foglio($foglio, $allegato, $sviluppo);
    $normPart  = analyticspro_gml_norm_particella($particella);

    // ------------------------------------------------------------------
    // Strategia 1 & 2: indice SQLite
    // Se l'indice è valido ma non contiene la particella, restituiamo null
    // senza eseguire la ricerca streaming (che è riservata ai comuni non indicizzati).
    // ------------------------------------------------------------------
    $indexValid = analyticspro_gml_parcel_index_valid($belfiore);
    if ($indexValid) {
        $dbPath = analyticspro_gml_index_dir() . '/' . $belfiore . '.sqlite';
        if (is_file($dbPath)) {
            return analyticspro_gml_lookup_in_index($dbPath, $codFoglio, $particella, $normPart);
        }
        return null;
    }

    error_log('[gml_catalog] Indice particelle non disponibile per ' . $belfiore
        . '. Indicizzare il comune dalla pagina Admin → Import GML.');

    // ------------------------------------------------------------------
    // Strategia 3: ricerca diretta in streaming sul _ple.gml
    // Usata SOLO quando l'indice non è disponibile, e solo se il file è ≤ 60 MB
    // (evita timeout su file molto grandi).
    // ------------------------------------------------------------------
    return analyticspro_gml_lookup_streaming($belfiore, $codFoglio, $normPart, $particella);
}

/**
 * Ricerca nell'indice SQLite con foglio esatto e fallback sui primi 4 caratteri.
 *
 * @return array{lat:float,lon:float,area_mq:float,ref:string,local_id:string,cod_foglio:string}|null
 */
function analyticspro_gml_lookup_in_index(
    string $dbPath,
    string $codFoglio,
    string $particella,
    string $normPart
): ?array {
    try {
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);

        // Tentativo 1: foglio esatto + particella esatta
        $row = analyticspro_gml_sqlite_lookup_row($db, $codFoglio, $particella, $normPart);
        if ($row !== null) {
            $db->close();
            return analyticspro_gml_make_result($row, $codFoglio, $particella);
        }

        // Tentativo 2: fallback foglio — primi 4 caratteri coincidenti.
        // Gestisce varianti allegato/sviluppo (es. "0033A0" vs "003300").
        $foglio4 = substr($codFoglio, 0, 4);
        $stmt = $db->prepare(
            'SELECT cod_foglio, lat, lon, area_mq FROM parcels
             WHERE substr(cod_foglio, 1, 4) = :f4
               AND (particella = :p OR particella_norm = :pn)
             LIMIT 1'
        );
        if ($stmt !== false) {
            $stmt->bindValue(':f4', $foglio4,  SQLITE3_TEXT);
            $stmt->bindValue(':p',  $particella, SQLITE3_TEXT);
            $stmt->bindValue(':pn', $normPart,   SQLITE3_TEXT);
            $result = $stmt->execute();
            if ($result !== false) {
                $row = $result->fetchArray(SQLITE3_ASSOC);
                if ($row !== false) {
                    $resolvedFoglio = (string) $row['cod_foglio'];
                    $db->close();
                    return analyticspro_gml_make_result($row, $resolvedFoglio, $particella);
                }
            }
        }

        $db->close();
        return null;
    } catch (Throwable $e) {
        error_log('[gml_catalog] SQLite error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Helper: esegue il doppio tentativo esatto/norm su un cod_foglio fisso.
 */
function analyticspro_gml_sqlite_lookup_row(
    SQLite3 $db,
    string  $codFoglio,
    string  $particella,
    string  $normPart
): ?array {
    // Match esatto
    $stmt = $db->prepare('SELECT lat, lon, area_mq FROM parcels WHERE cod_foglio = :cf AND particella = :p LIMIT 1');
    if ($stmt !== false) {
        $stmt->bindValue(':cf', $codFoglio, SQLITE3_TEXT);
        $stmt->bindValue(':p',  $particella, SQLITE3_TEXT);
        $result = $stmt->execute();
        if ($result !== false) {
            $row = $result->fetchArray(SQLITE3_ASSOC);
            if ($row !== false) {
                return $row;
            }
        }
    }
    // Match normalizzato
    $stmt2 = $db->prepare('SELECT lat, lon, area_mq FROM parcels WHERE cod_foglio = :cf AND particella_norm = :pn LIMIT 1');
    if ($stmt2 !== false) {
        $stmt2->bindValue(':cf', $codFoglio, SQLITE3_TEXT);
        $stmt2->bindValue(':pn', $normPart,   SQLITE3_TEXT);
        $result2 = $stmt2->execute();
        if ($result2 !== false) {
            $row = $result2->fetchArray(SQLITE3_ASSOC);
            if ($row !== false) {
                return $row;
            }
        }
    }
    return null;
}

/**
 * Costruisce l'array di risultato dalla riga SQLite.
 */
function analyticspro_gml_make_result(array $row, string $codFoglio, string $particella): array
{
    return [
        'lat'        => (float) $row['lat'],
        'lon'        => (float) $row['lon'],
        'area_mq'    => (float) ($row['area_mq'] ?? 0.0),
        'ref'        => '',
        'local_id'   => '',
        'cod_foglio' => $codFoglio,
    ];
}

/**
 * Ricerca diretta in streaming nel file _ple.gml (fallback quando l'indice
 * non è disponibile). Usa early-exit al primo match. Limitata ai file ≤ 60 MB
 * per evitare timeout su hosting con max_execution_time ridotto.
 *
 * @return array{lat:float,lon:float,area_mq:float,ref:string,local_id:string,cod_foglio:string}|null
 */
function analyticspro_gml_lookup_streaming(
    string $belfiore,
    string $codFoglio,
    string $normPart,
    string $particella,
    int    $maxBytes = 62914560 // 60 MB
): ?array {
    $catalog = analyticspro_gml_build_catalog();
    $entry   = $catalog[$belfiore] ?? null;
    if ($entry === null || empty($entry['ple'])) {
        return null;
    }

    $plePath = $entry['ple'];
    $fileSize = $entry['size_ple'] ?? 0;
    if ($fileSize > $maxBytes) {
        error_log('[gml_catalog] File _ple.gml troppo grande per ricerca diretta: '
            . $belfiore . ' (' . number_format($fileSize / 1048576, 1) . ' MB). Indicizzare il comune.');
        return null;
    }

    $foglio4 = substr($codFoglio, 0, 4);
    $found   = null;

    analyticspro_gml_stream_parcels($plePath, static function (array $f) use (
        $codFoglio, $foglio4, $normPart, $particella, &$found
    ): bool {
        $fFoglio = $f['codFoglio'];
        // Foglio esatto o fallback sui primi 4 caratteri
        if ($fFoglio !== $codFoglio && substr($fFoglio, 0, 4) !== $foglio4) {
            return false;
        }
        // Particella esatta o normalizzata
        $fNorm = analyticspro_gml_norm_particella($f['particella']);
        if ($f['particella'] !== $particella && $fNorm !== $normPart) {
            return false;
        }

        $extRings = $f['ext'];
        $intRings = $f['int'];
        if ($extRings === []) {
            return false;
        }

        $exterior = analyticspro_largest_ring($extRings) ?? $extRings[0];
        $pt       = analyticspro_interior_point($exterior, $intRings);
        if ($pt === null) {
            return false;
        }

        $areaMq = analyticspro_ring_area_m2($exterior);
        foreach ($intRings as $hole) {
            $areaMq -= analyticspro_ring_area_m2($hole);
        }
        if ($areaMq < 0) {
            $areaMq = 0.0;
        }

        $found = [
            'lat'        => $pt['lat'],
            'lon'        => $pt['lng'],
            'area_mq'    => $areaMq,
            'ref'        => $f['ref'] ?? '',
            'local_id'   => $f['localId'] ?? '',
            'cod_foglio' => $fFoglio,
        ];
        return true; // early exit
    });

    return $found;
}

/**
 * @return array{code:string|null,note:string}
 */
function analyticspro_gml_diagnose_lookup(string $belfiore, string $foglio, string $particella): array
{
    $belfiore = strtoupper(trim($belfiore));
    $catalog  = analyticspro_gml_build_catalog();
    $entry    = $catalog[$belfiore] ?? null;
    if ($entry === null || empty($entry['ple'])) {
        return [
            'code' => 'gml_mancante',
            'note' => 'Nessun file GML locale disponibile per il comune ' . $belfiore,
        ];
    }

    if (!analyticspro_gml_parcel_index_valid($belfiore)) {
        return [
            'code' => 'comune_non_indicizzato',
            'note' => 'Indice GML non disponibile o incompleto per ' . $belfiore,
        ];
    }

    $dbPath    = analyticspro_gml_index_dir() . '/' . $belfiore . '.sqlite';
    $codFoglio = analyticspro_gml_codice_foglio($foglio);
    $foglio4   = substr($codFoglio, 0, 4);
    $fogli     = [];
    $particelle = [];

    try {
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);

        $allFogliStmt = $db->query('SELECT DISTINCT cod_foglio FROM parcels ORDER BY cod_foglio');
        while ($allFogliStmt !== false && ($row = $allFogliStmt->fetchArray(SQLITE3_ASSOC)) !== false) {
            $fogli[] = (string) $row['cod_foglio'];
        }

        $matchStmt = $db->prepare(
            'SELECT DISTINCT cod_foglio FROM parcels WHERE cod_foglio = :cf OR substr(cod_foglio, 1, 4) = :f4 ORDER BY cod_foglio'
        );
        if ($matchStmt !== false) {
            $matchStmt->bindValue(':cf', $codFoglio, SQLITE3_TEXT);
            $matchStmt->bindValue(':f4', $foglio4, SQLITE3_TEXT);
            $result = $matchStmt->execute();
            $matchingFogli = [];
            while ($result !== false && ($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
                $matchingFogli[] = (string) $row['cod_foglio'];
            }
            if ($matchingFogli === []) {
                $db->close();
                return [
                    'code' => 'foglio_inesistente',
                    'note' => 'Foglio ' . $foglio . ' assente nel GML. Fogli presenti: '
                        . analyticspro_gml_truncate_list($fogli),
                ];
            }

            $partStmt = $db->prepare(
                'SELECT DISTINCT particella FROM parcels
                 WHERE cod_foglio = :cf OR substr(cod_foglio, 1, 4) = :f4
                 ORDER BY particella LIMIT 30'
            );
            if ($partStmt !== false) {
                $partStmt->bindValue(':cf', $codFoglio, SQLITE3_TEXT);
                $partStmt->bindValue(':f4', $foglio4, SQLITE3_TEXT);
                $partResult = $partStmt->execute();
                while ($partResult !== false && ($row = $partResult->fetchArray(SQLITE3_ASSOC)) !== false) {
                    $particelle[] = (string) $row['particella'];
                }
            }
        }

        $db->close();
    } catch (Throwable $exception) {
        return [
            'code' => 'comune_non_indicizzato',
            'note' => 'Indice GML non interrogabile: ' . $exception->getMessage(),
        ];
    }

    return [
        'code' => 'particella_inesistente',
        'note' => 'Particella ' . $particella . ' assente nel foglio ' . $foglio . '. Particelle presenti: '
            . analyticspro_gml_truncate_list($particelle),
    ];
}

function analyticspro_gml_truncate_list(array $values, int $limit = 12): string
{
    $values = array_values(array_unique(array_filter(array_map('strval', $values), static fn (string $value): bool => $value !== '')));
    if ($values === []) {
        return 'nessuna';
    }
    $slice = array_slice($values, 0, $limit);
    return implode(', ', $slice) . (count($values) > $limit ? ', …' : '');
}

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function analyticspro_gml_ensure_dirs(): void
{
    foreach ([analyticspro_gml_dir(), analyticspro_gml_index_dir()] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            file_put_contents($htaccess, "Require all denied\n");
        }
    }
}

/**
 * Cerca il nome del comune nel DB locale (tabella cadastral_comuni).
 * Ritorna stringa vuota se non trovato.
 */
function analyticspro_gml_nome_comune(string $belfiore): string
{
    try {
        $pdo  = analyticspro_db();
        $stmt = $pdo->prepare('SELECT nome_comune FROM cadastral_comuni WHERE cod_catastale = :b LIMIT 1');
        $stmt->execute(['b' => strtoupper($belfiore)]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (string) $row['nome_comune'] : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Sanitizza il nome di un file GML per lo storage.
 * Normalizza i caratteri non ASCII (spazi, apostrofi, ecc.) in underscore,
 * garantendo protezione da path traversal tramite basename().
 * Restituisce null se il nome non ha estensione .gml o .zip.
 */
function analyticspro_gml_sanitize_filename(string $raw): ?string
{
    $base = basename($raw);
    // Accetta solo .gml o .zip
    if (!preg_match('/\.(gml|zip)$/i', $base)) {
        return null;
    }
    // Normalizza i caratteri non sicuri in underscore (spazi, apostrofi, ecc.)
    $safe = preg_replace('/[^A-Za-z0-9._\-]+/', '_', $base);
    $safe = preg_replace('/_+/', '_', $safe ?? '');
    return ($safe !== '' && $safe !== '.' && $safe !== '..') ? $safe : null;
}

/**
 * Verifica che il contenuto di un file GML contenga almeno una feature catastale.
 */
function analyticspro_gml_validate_content(string $path): bool
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return false;
    }
    $content = '';
    $read    = 0;
    while ($read < 65536 && !feof($fh)) {
        $chunk    = (string) fread($fh, 4096);
        $content .= $chunk;
        $read    += strlen($chunk);
    }
    fclose($fh);

    return str_contains($content, 'CadastralParcel')
        || str_contains($content, 'CadastralZoning');
}
