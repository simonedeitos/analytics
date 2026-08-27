<?php

declare(strict_types=1);

/**
 * Parser GML particelle catastali con supporto a due dialetti:
 * - INSPIRE "puro" (camelCase, es. geometry / label / nationalCadastralReference / inspireId/localId)
 * - MapServer/AdE WFS (UPPERCASE, es. msGeometry / LABEL / NATIONALCADASTRALREFERENCE / INSPIREID_LOCALID)
 */
function analyticspro_parse_cadastral_parcels_gml(string $gmlPath): array
{
    if (!is_file($gmlPath) || !is_readable($gmlPath)) {
        throw new RuntimeException('File GML non leggibile: ' . $gmlPath);
    }

    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $dom->load($gmlPath);
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        throw new RuntimeException('Parsing XML fallito per: ' . basename($gmlPath));
    }

    $xpath = new DOMXPath($dom);
    $parcelNodes = $xpath->query('//*[local-name()="CadastralParcel"]');
    if (!$parcelNodes instanceof DOMNodeList) {
        return [];
    }

    $parcels = [];
    foreach ($parcelNodes as $parcelNode) {
        $points = analyticspro_extract_polygon_points_from_parcel($xpath, $parcelNode);
        if ($points === []) {
            continue;
        }
        $inspireIdLocal = analyticspro_xpath_string($xpath, './/*[local-name()="inspireId"]//*[local-name()="localId"][1]', $parcelNode)
            ?? analyticspro_xpath_string_ci($xpath, 'inspireid_localid', $parcelNode)
            ?? analyticspro_xpath_string_ci($xpath, 'inspireidlocalid', $parcelNode);
        $inspireIdNamespace = analyticspro_xpath_string($xpath, './/*[local-name()="inspireId"]//*[local-name()="namespace"][1]', $parcelNode)
            ?? analyticspro_xpath_string_ci($xpath, 'inspireid_namespace', $parcelNode)
            ?? analyticspro_xpath_string_ci($xpath, 'inspireidnamespace', $parcelNode);

        $parcels[] = [
            'inspire_id' => analyticspro_compose_inspire_id($inspireIdNamespace, $inspireIdLocal),
            'label' => analyticspro_xpath_string_ci($xpath, 'label', $parcelNode),
            'national_reference' => analyticspro_xpath_string_ci($xpath, 'nationalcadastralreference', $parcelNode),
            'area_mq' => analyticspro_xpath_float_ci($xpath, 'areavalue', $parcelNode),
            'points' => $points,
        ];
    }

    return $parcels;
}

function analyticspro_extract_polygon_points_from_parcel(DOMXPath $xpath, DOMNode $parcelNode): array
{
    $polygonQueries = [
        './/*[local-name()="geometry"]//*[local-name()="Polygon"][1]',
        './/*[local-name()="msGeometry"]//*[local-name()="Polygon"][1]',
        './/*[local-name()="MultiSurface"]//*[local-name()="surfaceMember"]//*[local-name()="Polygon"][1]',
        './/*[local-name()="Polygon"][1]',
    ];

    $polygonNode = null;
    foreach ($polygonQueries as $query) {
        $candidate = $xpath->query($query, $parcelNode)?->item(0);
        if ($candidate instanceof DOMNode) {
            $polygonNode = $candidate;
            break;
        }
    }
    if (!$polygonNode instanceof DOMNode) {
        return [];
    }

    $srsName = analyticspro_extract_node_srs_name($polygonNode);

    $posListNode = $xpath->query('.//*[local-name()="exterior"]//*[local-name()="posList"][1]', $polygonNode)?->item(0);
    if ($posListNode instanceof DOMNode) {
        $dimension = analyticspro_read_srs_dimension($posListNode) ?? analyticspro_read_srs_dimension($polygonNode) ?? 2;
        return analyticspro_parse_gml_coordinate_sequence((string) $posListNode->textContent, $srsName, $dimension);
    }

    $posNodes = $xpath->query('.//*[local-name()="exterior"]//*[local-name()="pos"]', $polygonNode);
    if (!$posNodes instanceof DOMNodeList || $posNodes->length === 0) {
        return [];
    }

    $firstPos = $posNodes->item(0);
    $dimension = ($firstPos instanceof DOMNode ? analyticspro_read_srs_dimension($firstPos) : null)
        ?? analyticspro_read_srs_dimension($polygonNode)
        ?? 2;

    $coords = [];
    foreach ($posNodes as $posNode) {
        $coords[] = trim((string) $posNode->textContent);
    }

    return analyticspro_parse_gml_coordinate_sequence(implode(' ', $coords), $srsName, $dimension);
}

function analyticspro_extract_node_srs_name(DOMNode $node): ?string
{
    if ($node instanceof DOMElement && $node->hasAttribute('srsName')) {
        return trim((string) $node->getAttribute('srsName')) ?: null;
    }

    if ($node instanceof DOMElement) {
        foreach ($node->getElementsByTagName('*') as $child) {
            if (!$child->hasAttribute('srsName')) {
                continue;
            }

            $name = trim((string) $child->getAttribute('srsName'));
            if ($name !== '') {
                return $name;
            }
        }
    }

    return null;
}

function analyticspro_read_srs_dimension(DOMNode $node): ?int
{
    if ($node instanceof DOMElement && $node->hasAttribute('srsDimension')) {
        $dimension = (int) $node->getAttribute('srsDimension');
        return $dimension >= 2 ? $dimension : null;
    }

    return null;
}

function analyticspro_parse_gml_coordinate_sequence(string $raw, ?string $srsName = null, int $dimension = 2): array
{
    preg_match_all('/[-+]?\d*\.?\d+(?:[eE][-+]?\d+)?/', $raw, $matches);
    $numbers = array_map('floatval', $matches[0] ?? []);
    if (count($numbers) < 4) {
        return [];
    }

    $dimension = max(2, $dimension);
    if (count($numbers) < $dimension * 2) {
        return [];
    }

    $latFirst = true;
    $first = $numbers[0];
    $second = $numbers[1];
    if (analyticspro_looks_like_lng_lat($first, $second)) {
        $latFirst = false;
    } elseif (!analyticspro_looks_like_lat_lng($first, $second) && analyticspro_srs_prefers_lng_lat($srsName)) {
        $latFirst = false;
    }

    $points = [];
    for ($i = 0; $i + ($dimension - 1) < count($numbers); $i += $dimension) {
        $a = $numbers[$i];
        $b = $numbers[$i + 1];

        $lat = $latFirst ? $a : $b;
        $lng = $latFirst ? $b : $a;
        $points[] = ['lat' => $lat, 'lng' => $lng];
    }

    return analyticspro_normalize_polygon_points($points);
}

function analyticspro_srs_prefers_lng_lat(?string $srsName): bool
{
    if (!is_string($srsName) || $srsName === '') {
        return false;
    }

    $normalized = strtoupper($srsName);
    return str_contains($normalized, 'EPSG::3857')
        || str_contains($normalized, 'EPSG/0/3857')
        || str_contains($normalized, 'EPSG:3857')
        || str_contains($normalized, 'EPSG::3003')
        || str_contains($normalized, 'EPSG/0/3003')
        || str_contains($normalized, 'EPSG:3003')
        || str_contains($normalized, 'EPSG::3004')
        || str_contains($normalized, 'EPSG/0/3004')
        || str_contains($normalized, 'EPSG:3004')
        || str_contains($normalized, 'EPSG::32632')
        || str_contains($normalized, 'EPSG/0/32632')
        || str_contains($normalized, 'EPSG:32632')
        || str_contains($normalized, 'EPSG::32633')
        || str_contains($normalized, 'EPSG/0/32633')
        || str_contains($normalized, 'EPSG:32633');
}

function analyticspro_looks_like_lat_lng(float $first, float $second): bool
{
    return $first >= 35.0 && $first <= 48.5 && $second >= 6.0 && $second <= 19.5;
}

function analyticspro_looks_like_lng_lat(float $first, float $second): bool
{
    return $first >= 6.0 && $first <= 19.5 && $second >= 35.0 && $second <= 48.5;
}

function analyticspro_normalize_polygon_points(array $points): array
{
    $normalized = [];
    foreach ($points as $point) {
        if (!is_array($point) || !isset($point['lat'], $point['lng'])) {
            continue;
        }

        $lat = (float) $point['lat'];
        $lng = (float) $point['lng'];
        if (!is_finite($lat) || !is_finite($lng)) {
            continue;
        }

        $last = $normalized[count($normalized) - 1] ?? null;
        if (is_array($last) && abs($last['lat'] - $lat) < 1e-12 && abs($last['lng'] - $lng) < 1e-12) {
            continue;
        }

        $normalized[] = ['lat' => $lat, 'lng' => $lng];
    }

    if (count($normalized) >= 2) {
        $first = $normalized[0];
        $last = $normalized[count($normalized) - 1];
        if (abs($first['lat'] - $last['lat']) > 1e-12 || abs($first['lng'] - $last['lng']) > 1e-12) {
            $normalized[] = $first;
        }
    }

    return $normalized;
}

function analyticspro_xpath_string(DOMXPath $xpath, string $query, DOMNode $context): ?string
{
    $node = $xpath->query($query, $context)?->item(0);
    if (!$node instanceof DOMNode) {
        return null;
    }

    $value = trim((string) $node->textContent);
    return $value === '' ? null : $value;
}

function analyticspro_xpath_string_ci(DOMXPath $xpath, string $localNameLowercase, DOMNode $context): ?string
{
    $safeName = strtolower(trim($localNameLowercase));
    if ($safeName === '' || !preg_match('/^[a-z0-9_]+$/', $safeName)) {
        return null;
    }

    $query = './/*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="' . $safeName . '"][1]';
    return analyticspro_xpath_string($xpath, $query, $context);
}

function analyticspro_xpath_float(DOMXPath $xpath, string $query, DOMNode $context): ?float
{
    $value = analyticspro_xpath_string($xpath, $query, $context);
    if (!is_string($value)) {
        return null;
    }

    if (!preg_match('/[-+]?\d*\.?\d+(?:[eE][-+]?\d+)?/', $value, $match)) {
        return null;
    }

    return (float) $match[0];
}

function analyticspro_xpath_float_ci(DOMXPath $xpath, string $localNameLowercase, DOMNode $context): ?float
{
    $value = analyticspro_xpath_string_ci($xpath, $localNameLowercase, $context);
    if (!is_string($value)) {
        return null;
    }

    if (!preg_match('/[-+]?\d*\.?\d+(?:[eE][-+]?\d+)?/', $value, $match)) {
        return null;
    }

    return (float) $match[0];
}

function analyticspro_compose_inspire_id(?string $namespace, ?string $localId): ?string
{
    if (!is_string($localId) || trim($localId) === '') {
        return null;
    }

    $localId = trim($localId);
    $namespace = is_string($namespace) ? trim($namespace) : '';
    if ($namespace === '') {
        return $localId;
    }

    return str_starts_with($localId, $namespace) ? $localId : $namespace . $localId;
}

function analyticspro_extract_cadastral_parts(?string $nationalReference, ?string $label): ?array
{
    foreach ([$nationalReference, $label] as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }

        $candidate = strtoupper(trim($candidate));
        if ($candidate === '') {
            continue;
        }

        $candidate = preg_replace('/\s+/', '.', $candidate) ?? $candidate;
        $candidate = preg_replace('/[^A-Z0-9._\/-]/', '', $candidate) ?? $candidate;

        if (preg_match('/^[A-Z0-9]{4}[._\/-](.+)$/', $candidate, $prefixed)) {
            $candidate = $prefixed[1];
        }

        if (preg_match('/^(?:(?<sezione>[A-Z]{1,4})[._\/-])?(?<foglio>\d{1,6})[._\/-](?<particella>[A-Z0-9]{1,20})$/', $candidate, $parts)) {
            return [
                'sezione' => ($parts['sezione'] ?? '') !== '' ? (string) $parts['sezione'] : null,
                'foglio' => (string) $parts['foglio'],
                'particella' => (string) $parts['particella'],
            ];
        }

        $tokens = preg_split('/[._\/-]+/', $candidate, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens) || count($tokens) < 2) {
            continue;
        }

        $particella = (string) $tokens[count($tokens) - 1];
        $foglio = (string) $tokens[count($tokens) - 2];
        $sezioneTokens = array_slice($tokens, 0, -2);
        $sezione = $sezioneTokens !== [] ? implode('.', $sezioneTokens) : null;

        // Dialetto AdE osservato: D185_090400.1 -> prefisso comune rimosso, "090400" mantenuto
        // interamente come foglio (stringa, inclusi eventuali zeri iniziali), "1" come particella.
        if ($foglio !== '' && ctype_digit($foglio) && preg_match('/^[A-Z0-9]+$/', $particella)) {
            return [
                'sezione' => $sezione !== '' ? $sezione : null,
                'foglio' => $foglio,
                'particella' => $particella,
            ];
        }
    }

    return null;
}

function analyticspro_polygon_to_wkt(array $polygonPoints): ?string
{
    $points = analyticspro_normalize_polygon_points($polygonPoints);
    if (count($points) < 4) {
        return null;
    }

    $pairs = [];
    foreach ($points as $point) {
        $pairs[] = analyticspro_format_coord((float) $point['lng']) . ' ' . analyticspro_format_coord((float) $point['lat']);
    }

    return 'POLYGON((' . implode(', ', $pairs) . '))';
}

function analyticspro_point_to_wkt(array $point): ?string
{
    if (!isset($point['lat'], $point['lng'])) {
        return null;
    }

    $lat = (float) $point['lat'];
    $lng = (float) $point['lng'];
    if (!is_finite($lat) || !is_finite($lng)) {
        return null;
    }

    return 'POINT(' . analyticspro_format_coord($lng) . ' ' . analyticspro_format_coord($lat) . ')';
}

function analyticspro_format_coord(float $value): string
{
    $formatted = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    return $formatted === '' ? '0' : $formatted;
}

function analyticspro_compute_polygon_interior_point(array $polygonPoints): array
{
    $points = analyticspro_polygon_without_closing_vertex($polygonPoints);
    if (count($points) < 3) {
        return [
            'point' => null,
            'inside' => false,
            'strategy' => 'invalid_polygon',
        ];
    }

    $centroid = analyticspro_polygon_simple_centroid($points);
    if ($centroid !== null && analyticspro_point_in_polygon($centroid, $polygonPoints)) {
        return [
            'point' => $centroid,
            'inside' => true,
            'strategy' => 'centroid',
        ];
    }

    $maxVertices = min(5, count($points));
    for ($i = 0; $i < $maxVertices; $i++) {
        if (analyticspro_point_in_polygon($points[$i], $polygonPoints)) {
            return [
                'point' => $points[$i],
                'inside' => true,
                'strategy' => 'vertex_fallback',
            ];
        }
    }

    if ($centroid !== null) {
        $nearestIndexes = analyticspro_nearest_vertex_indexes($centroid, $points, 5);
        foreach ($nearestIndexes as $index) {
            $nextIndex = ($index + 1) % count($points);
            $midpoint = [
                'lat' => ($points[$index]['lat'] + $points[$nextIndex]['lat']) / 2,
                'lng' => ($points[$index]['lng'] + $points[$nextIndex]['lng']) / 2,
            ];
            if (analyticspro_point_in_polygon($midpoint, $polygonPoints)) {
                return [
                    'point' => $midpoint,
                    'inside' => true,
                    'strategy' => 'edge_midpoint_fallback',
                ];
            }
        }

        return [
            'point' => $centroid,
            'inside' => false,
            'strategy' => 'centroid_outside_fallback',
        ];
    }

    return [
        'point' => $points[0],
        'inside' => false,
        'strategy' => 'vertex_outside_fallback',
    ];
}

function analyticspro_nearest_vertex_indexes(array $center, array $points, int $limit): array
{
    $distances = [];
    foreach ($points as $index => $point) {
        $dx = (float) $point['lng'] - (float) $center['lng'];
        $dy = (float) $point['lat'] - (float) $center['lat'];
        $distances[$index] = ($dx * $dx) + ($dy * $dy);
    }

    asort($distances, SORT_NUMERIC);
    return array_slice(array_keys($distances), 0, $limit);
}

function analyticspro_polygon_simple_centroid(array $points): ?array
{
    if ($points === []) {
        return null;
    }

    $latSum = 0.0;
    $lngSum = 0.0;
    foreach ($points as $point) {
        $latSum += (float) $point['lat'];
        $lngSum += (float) $point['lng'];
    }

    return [
        'lat' => $latSum / count($points),
        'lng' => $lngSum / count($points),
    ];
}

function analyticspro_polygon_without_closing_vertex(array $polygonPoints): array
{
    $points = analyticspro_normalize_polygon_points($polygonPoints);
    if (count($points) < 2) {
        return $points;
    }

    $first = $points[0];
    $last = $points[count($points) - 1];
    if (abs($first['lat'] - $last['lat']) < 1e-12 && abs($first['lng'] - $last['lng']) < 1e-12) {
        array_pop($points);
    }

    return $points;
}

function analyticspro_point_in_polygon(array $point, array $polygonPoints): bool
{
    if (!isset($point['lat'], $point['lng'])) {
        return false;
    }

    $polygon = analyticspro_polygon_without_closing_vertex($polygonPoints);
    $vertices = count($polygon);
    if ($vertices < 3) {
        return false;
    }

    $x = (float) $point['lng'];
    $y = (float) $point['lat'];

    $inside = false;
    for ($i = 0, $j = $vertices - 1; $i < $vertices; $j = $i++) {
        $xi = (float) $polygon[$i]['lng'];
        $yi = (float) $polygon[$i]['lat'];
        $xj = (float) $polygon[$j]['lng'];
        $yj = (float) $polygon[$j]['lat'];

        if (analyticspro_point_on_segment($x, $y, $xi, $yi, $xj, $yj)) {
            return true;
        }

        $intersects = (($yi > $y) !== ($yj > $y))
            && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-16) + $xi);

        if ($intersects) {
            $inside = !$inside;
        }
    }

    return $inside;
}

function analyticspro_point_on_segment(float $px, float $py, float $x1, float $y1, float $x2, float $y2): bool
{
    $cross = ($py - $y1) * ($x2 - $x1) - ($px - $x1) * ($y2 - $y1);
    if (abs($cross) > 1e-10) {
        return false;
    }

    $dot = ($px - $x1) * ($x2 - $x1) + ($py - $y1) * ($y2 - $y1);
    if ($dot < 0) {
        return false;
    }

    $lenSq = ($x2 - $x1) * ($x2 - $x1) + ($y2 - $y1) * ($y2 - $y1);
    return $dot <= $lenSq + 1e-12;
}
