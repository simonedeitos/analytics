<?php

declare(strict_types=1);

/**
 * Parser GML in streaming (chunk-based) per file ADE MapServer.
 *
 * Non usa DOMDocument::load() (che carica l'intero file in memoria) né
 * XMLReader::next($localName) (che confronta il nome qualificato e quindi
 * salta alla fine del documento se si passa solo il local name).
 *
 * Algoritmo:
 *  1. Legge il file a blocchi da 1 MB con fread().
 *  2. Accumula i chunk in un buffer.
 *  3. Isola ogni elemento <X:CadastralParcel> … </X:CadastralParcel>
 *     (qualunque prefisso) tramite regex, gestendo l'elemento a cavallo di
 *     due chunk.
 *  4. Estrae i campi con regex insensibili a maiuscole/minuscole e a
 *     qualunque prefisso namespace.
 *  5. Esclude i posList interni a gml:Envelope/boundedBy.
 *
 * API pubblica:
 *   analyticspro_gml_stream_parcels(string $path, callable $cb): int
 *   analyticspro_gml_stream_zonings(string $path, callable $cb): int
 *
 * Il callback riceve un array con:
 *   ref, localId, label, belfiore, codFoglio, particella,
 *   ext (rings), int (rings), areaValue
 * Restituire true dal callback interrompe la scansione (early exit).
 */

const ANALYTICSPRO_GML_CHUNK_SIZE = 1048576; // 1 MB

/**
 * Estrae tutte le particelle catastali (CadastralParcel) in streaming.
 *
 * @param  callable(array):bool  $cb
 * @return int  Numero di feature elaborate.
 */
function analyticspro_gml_stream_parcels(string $path, callable $cb): int
{
    return analyticspro_gml_stream_elements($path, 'CadastralParcel', $cb, 'parcel');
}

/**
 * Estrae tutti i fogli catastali (CadastralZoning) in streaming.
 *
 * @param  callable(array):bool  $cb
 * @return int  Numero di feature elaborate.
 */
function analyticspro_gml_stream_zonings(string $path, callable $cb): int
{
    return analyticspro_gml_stream_elements($path, 'CadastralZoning', $cb, 'zoning');
}

/**
 * Legge il valore di numberMatched dall'intestazione del file GML.
 * Legge solo i primi 4 KB per non caricare il file intero.
 */
function analyticspro_gml_number_matched(string $path): ?int
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return null;
    }
    $header = (string) fread($fh, 4096);
    fclose($fh);

    if (preg_match('/numberMatched\s*=\s*["\'](\d+)["\']/', $header, $m)) {
        return (int) $m[1];
    }
    return null;
}

// ---------------------------------------------------------------------------
// Implementazione interna
// ---------------------------------------------------------------------------

function analyticspro_gml_stream_elements(string $path, string $localTag, callable $cb, string $mode): int
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Impossibile aprire il file GML: ' . $path);
    }

    $buffer  = '';
    $count   = 0;
    $stopped = false;

    // Pattern per trovare il tag di apertura e chiusura (qualunque prefisso)
    $openPat  = '/<(?:[A-Za-z_][\w.\-]*:)?' . preg_quote($localTag, '/') . '[\s>\/]/i';
    $closePat = '/<\/(?:[A-Za-z_][\w.\-]*:)?' . preg_quote($localTag, '/') . '\s*>/i';

    while (!$stopped && !feof($fh)) {
        $chunk   = (string) fread($fh, ANALYTICSPRO_GML_CHUNK_SIZE);
        $buffer .= $chunk;

        // Estrai tutti gli elementi completi presenti nel buffer
        while (!$stopped) {
            // Trova inizio elemento
            if (!preg_match($openPat, $buffer, $openMatch, PREG_OFFSET_CAPTURE)) {
                // Nessun elemento nel buffer: scarta tutto tranne l'ultimo pezzetto
                // (che potrebbe contenere l'inizio di un tag parziale)
                $keep = max(0, strlen($buffer) - 200);
                $buffer = substr($buffer, $keep);
                break;
            }

            $startPos = (int) $openMatch[0][1];

            // Trova fine elemento
            if (!preg_match($closePat, $buffer, $closeMatch, PREG_OFFSET_CAPTURE, $startPos)) {
                // Elemento a cavallo del chunk: aspetta il prossimo chunk
                // Scarta ciò che precede l'apertura
                $buffer = substr($buffer, $startPos);
                break;
            }

            $closeTagLen = strlen($closeMatch[0][0]);
            $endPos      = (int) $closeMatch[0][1] + $closeTagLen;

            $xml    = substr($buffer, $startPos, $endPos - $startPos);
            $buffer = substr($buffer, $endPos);

            $feature = $mode === 'parcel'
                ? analyticspro_gml_parse_parcel_xml($xml)
                : analyticspro_gml_parse_zoning_xml($xml);

            if ($feature !== null) {
                $count++;
                if ($cb($feature) === true) {
                    $stopped = true;
                }
            }
        }
    }

    fclose($fh);
    return $count;
}

// ---------------------------------------------------------------------------
// Parsing di un singolo elemento XML (stringa, non DOM)
// ---------------------------------------------------------------------------

/**
 * Estrae un valore testuale da un tag (insensibile a prefisso e case).
 */
function analyticspro_gml_rx_field(string $xml, string $tag): ?string
{
    $pattern = '/<(?:[A-Za-z_][\w.\-]*:)?' . preg_quote($tag, '/') . '\b[^>]*>(.*?)<\/(?:[A-Za-z_][\w.\-]*:)?' . preg_quote($tag, '/') . '\s*>/is';
    if (preg_match($pattern, $xml, $m)) {
        return trim($m[1]);
    }
    return null;
}

/**
 * Rimuove la sezione gml:boundedBy/gml:Envelope dal frammento XML
 * per evitare che i suoi posList vengano inclusi nella geometria.
 */
function analyticspro_gml_strip_bounded_by(string $xml): string
{
    // Rimuove <...:boundedBy>…</:boundedBy> (qualunque prefisso)
    return (string) preg_replace(
        '/<(?:[A-Za-z_][\w.\-]*:)?boundedBy\b.*?<\/(?:[A-Za-z_][\w.\-]*:)?boundedBy\s*>/is',
        '',
        $xml
    );
}

/**
 * Estrae i ring (exterior e interior) da un frammento XML di poligono/MultiSurface.
 * Esclude i posList interni a Envelope (boundedBy).
 *
 * @return array{ext: list<list<array{lat:float,lng:float}>>, int: list<list<array{lat:float,lng:float}>>}
 */
function analyticspro_gml_extract_rings(string $xml): array
{
    $xml = analyticspro_gml_strip_bounded_by($xml);

    // Trova tutti i blocchi <exterior>…</exterior>
    $extRings = [];
    $intRings = [];

    // Estrae blocchi exterior
    preg_match_all(
        '/<(?:[A-Za-z_][\w.\-]*:)?exterior\b[^>]*>(.*?)<\/(?:[A-Za-z_][\w.\-]*:)?exterior\s*>/is',
        $xml,
        $extMatches
    );
    foreach ($extMatches[1] as $ringXml) {
        $pts = analyticspro_gml_extract_pos_list($ringXml);
        if (count($pts) >= 3) {
            $extRings[] = $pts;
        }
    }

    // Estrae blocchi interior
    preg_match_all(
        '/<(?:[A-Za-z_][\w.\-]*:)?interior\b[^>]*>(.*?)<\/(?:[A-Za-z_][\w.\-]*:)?interior\s*>/is',
        $xml,
        $intMatches
    );
    foreach ($intMatches[1] as $ringXml) {
        $pts = analyticspro_gml_extract_pos_list($ringXml);
        if (count($pts) >= 3) {
            $intRings[] = $pts;
        }
    }

    // Fallback: se non ci sono exterior espliciti, prova con posList diretti (file senza exterior/interior)
    if ($extRings === []) {
        preg_match_all(
            '/<(?:[A-Za-z_][\w.\-]*:)?posList\b[^>]*>(.*?)<\/(?:[A-Za-z_][\w.\-]*:)?posList\s*>/is',
            $xml,
            $plm
        );
        foreach ($plm[1] as $posListContent) {
            $pts = analyticspro_gml_parse_pos_list_content($posListContent);
            if (count($pts) >= 3) {
                $extRings[] = $pts;
            }
        }
    }

    return ['ext' => $extRings, 'int' => $intRings];
}

/**
 * Estrae i punti da un frammento che contiene un gml:posList.
 *
 * @return list<array{lat:float,lng:float}>
 */
function analyticspro_gml_extract_pos_list(string $ringXml): array
{
    if (!preg_match(
        '/<(?:[A-Za-z_][\w.\-]*:)?posList\b[^>]*>(.*?)<\/(?:[A-Za-z_][\w.\-]*:)?posList\s*>/is',
        $ringXml,
        $m
    )) {
        return [];
    }
    return analyticspro_gml_parse_pos_list_content($m[1]);
}

/**
 * Converte una stringa di coordinate EPSG:6706 (lat lon lat lon…) in array di punti.
 *
 * @return list<array{lat:float,lng:float}>
 */
function analyticspro_gml_parse_pos_list_content(string $content): array
{
    preg_match_all('/[-+]?\d*\.?\d+(?:[eE][-+]?\d+)?/', $content, $nums);
    $numbers = array_map('floatval', $nums[0] ?? []);
    $count   = count($numbers);
    if ($count < 6) {
        return [];
    }

    $points = [];
    for ($i = 0; $i + 1 < $count; $i += 2) {
        $points[] = ['lat' => $numbers[$i], 'lng' => $numbers[$i + 1]];
    }
    return $points;
}

/**
 * Decompone un riferimento catastale ADE nel formato B394_005000.1
 * (con o senza separatore sezione-foglio, es. M393B000300.369).
 *
 * @return array{belfiore:string,codFoglio:string,particella:string}|null
 */
function analyticspro_gml_parse_ref(string $ref): ?array
{
    // Formato standard: BBBB_FFFFFF.P  (4 char Belfiore, underscore, fino a 6 char foglio, punto, particella)
    // Il codice foglio nel riferimento può già essere il codice completo a 6 char (es. 005000)
    // oppure solo il numero (es. 33)
    if (preg_match('/^([A-Z0-9]{4})_([0-9]{4}[A-Z0][A-Z0]|[0-9]{4}[A-Z0]|[0-9]{1,4})\.(.+)$/i', $ref, $m)) {
        $foglio = strtoupper($m[2]);
        $len = strlen($foglio);
        if ($len <= 4) {
            $foglio = str_pad($foglio, 4, '0', STR_PAD_LEFT) . '00';
        } elseif ($len === 5) {
            $foglio .= '0';
        }
        // $len === 6: già completo
        return ['belfiore' => strtoupper($m[1]), 'codFoglio' => $foglio, 'particella' => $m[3]];
    }

    // Formato con sezione censuaria attaccata: BBBBSFFFFFF.P  (4 char Belfiore + 1 char sezione + 6 char foglio)
    if (preg_match('/^([A-Z0-9]{4})([A-Z])([0-9]{6})\.(.+)$/i', $ref, $m)) {
        return ['belfiore' => strtoupper($m[1]), 'codFoglio' => strtoupper($m[3]), 'particella' => $m[4]];
    }

    return null;
}

/**
 * Produce il codice foglio a 6 caratteri: NNNN (4 cifre) + A (allegato) + S (sviluppo).
 * '0' in posizione 5 o 6 significa assente.
 */
function analyticspro_gml_codice_foglio(string $foglio, string $allegato = '', string $sviluppo = ''): string
{
    $num = (int) $foglio;
    $all = ($allegato !== '' && $allegato !== '0') ? strtoupper($allegato[0]) : '0';
    $svi = ($sviluppo !== '' && $sviluppo !== '0') ? strtoupper($sviluppo[0]) : '0';
    return sprintf('%04d', $num) . $all . $svi;
}

// ---------------------------------------------------------------------------
// Parser per CadastralParcel
// ---------------------------------------------------------------------------

function analyticspro_gml_parse_parcel_xml(string $xml): ?array
{
    $ref     = analyticspro_gml_rx_field($xml, 'NATIONALCADASTRALREFERENCE');
    $localId = analyticspro_gml_rx_field($xml, 'INSPIREID_LOCALID');
    $label   = analyticspro_gml_rx_field($xml, 'LABEL');
    $area    = analyticspro_gml_rx_field($xml, 'AREAVALUE');

    if ($ref === null) {
        // Fallback INSPIRE puro
        $ref     = analyticspro_gml_rx_field($xml, 'nationalCadastralReference');
        $localId = analyticspro_gml_rx_field($xml, 'localId');
        $label   = analyticspro_gml_rx_field($xml, 'label');
        $area    = analyticspro_gml_rx_field($xml, 'areaValue');
    }

    if ($ref === null) {
        return null;
    }

    $belfiore  = analyticspro_gml_rx_field($xml, 'ADMINISTRATIVEUNIT')
        ?? analyticspro_gml_rx_field($xml, 'administrativeUnit');

    $rings = analyticspro_gml_extract_rings($xml);

    $parsed = analyticspro_gml_parse_ref($ref);

    return [
        'ref'        => $ref,
        'localId'    => $localId ?? '',
        'label'      => $label ?? '',
        'belfiore'   => $belfiore ?? ($parsed['belfiore'] ?? ''),
        'codFoglio'  => $parsed['codFoglio'] ?? '',
        'particella' => $parsed['particella'] ?? ($label ?? ''),
        'ext'        => $rings['ext'],
        'int'        => $rings['int'],
        'areaValue'  => $area !== null ? (float) $area : null,
    ];
}

// ---------------------------------------------------------------------------
// Parser per CadastralZoning
// ---------------------------------------------------------------------------

function analyticspro_gml_parse_zoning_xml(string $xml): ?array
{
    $ref     = analyticspro_gml_rx_field($xml, 'NATIONALCADASTRALZONINGREFERENCE');
    $label   = analyticspro_gml_rx_field($xml, 'LABEL');
    $level   = analyticspro_gml_rx_field($xml, 'LEVEL');
    $localId = analyticspro_gml_rx_field($xml, 'INSPIREID_LOCALID');

    if ($ref === null) {
        $ref     = analyticspro_gml_rx_field($xml, 'nationalCadastralZoningReference');
        $label   = analyticspro_gml_rx_field($xml, 'label');
        $level   = analyticspro_gml_rx_field($xml, 'level');
        $localId = analyticspro_gml_rx_field($xml, 'localId');
    }

    if ($ref === null) {
        return null;
    }

    $belfiore = analyticspro_gml_rx_field($xml, 'ADMINISTRATIVEUNIT')
        ?? analyticspro_gml_rx_field($xml, 'administrativeUnit');

    // Decomponi il riferimento foglio: B394_000100 → belfiore B394, foglio 000100
    $parsedBelfiore = '';
    $parsedFoglio   = '';
    if (preg_match('/^([A-Z0-9]{4})_([0-9A-Z]{4,6})$/i', $ref, $m)) {
        $parsedBelfiore = strtoupper($m[1]);
        $foglio = $m[2];
        if (strlen($foglio) <= 4) {
            $foglio = str_pad($foglio, 4, '0', STR_PAD_LEFT) . '00';
        } elseif (strlen($foglio) === 5) {
            $foglio .= '0';
        }
        $parsedFoglio = strtoupper($foglio);
    } elseif (preg_match('/^([A-Z0-9]{4})([A-Z])([0-9]{6})$/i', $ref, $m)) {
        $parsedBelfiore = strtoupper($m[1]);
        $parsedFoglio   = strtoupper($m[3]);
    }

    $rings = analyticspro_gml_extract_rings($xml);

    return [
        'ref'      => $ref,
        'localId'  => $localId ?? '',
        'label'    => $label ?? '',
        'level'    => $level ?? '',
        'belfiore' => $belfiore ?? $parsedBelfiore,
        'codFoglio'=> $parsedFoglio,
        'ext'      => $rings['ext'],
        'int'      => $rings['int'],
    ];
}
