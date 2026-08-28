<?php

declare(strict_types=1);

/**
 * Calcolo del centroide geometrico e punto interno garantito per poligoni GML.
 *
 * - Algoritmo shoelace per centroide d'area (non semplice media dei vertici).
 * - Sottrazione dei buchi (interior rings) dal momento d'area.
 * - Verifica pointInPolygon: se il centroide cade fuori dal poligono convesso/concavo,
 *   fallback su punto interno calcolato con scanline orizzontale a metà latitudine.
 * - Calcolo superficie approssimata in m² con proiezione equirettangolare locale.
 *
 * I coordinate sono espresse come array ['lat' => float, 'lng' => float] e
 * corrispondono al sistema EPSG:6706 (RDN2008, ≈ WGS84).
 */

/**
 * Calcola il centroide d'area di un poligono.
 *
 * Robusto rispetto al verso di rotazione dei ring: usa abs() sull'area
 * e sottrae esplicitamente i buchi, indipendentemente dal loro orientamento.
 *
 * @param  list<array{lat:float,lng:float}>  $exterior  Ring esterno (chiuso o aperto).
 * @param  list<list<array{lat:float,lng:float}>>  $interiors  Ring interni (buchi).
 * @return array{lat:float,lng:float}|null
 */
function analyticspro_centroid(array $exterior, array $interiors = []): ?array
{
    [$cxNum, $cyNum, $area] = analyticspro_centroid_ring($exterior, +1.0);

    // Normalizza il verso: l'area dell'esterno deve essere sempre positiva
    if ($area < 0) {
        $area  = -$area;
        $cxNum = -$cxNum;
        $cyNum = -$cyNum;
    }
    if ($area < 1e-15) {
        return null;
    }

    foreach ($interiors as $hole) {
        [$hxNum, $hyNum, $hArea] = analyticspro_centroid_ring($hole, +1.0);
        // Normalizza il verso del buco: area positiva, poi sottraiamo
        if ($hArea < 0) {
            $hArea = -$hArea;
            $hxNum = -$hxNum;
            $hyNum = -$hyNum;
        }
        $cxNum -= $hxNum;
        $cyNum -= $hyNum;
        $area  -= $hArea;
    }

    if (abs($area) < 1e-15) {
        return null;
    }

    $factor = 1.0 / (6.0 * $area);
    return ['lat' => $cxNum * $factor, 'lng' => $cyNum * $factor];
}

/**
 * Calcola il centroide pesato di un singolo ring (formula shoelace).
 * $sign = +1 per ring esterno, -1 per buchi.
 *
 * Le coordinate vengono traslate all'origine del primo vertice per
 * evitare cancellazione numerica con coordinate geografiche grandi.
 *
 * Restituisce (cx_numeratore, cy_numeratore, area_signed) dove:
 *   cx_num  = Σ (lat_i + lat_{i+1}) * cross_i  (nel sistema traslato + offset re-aggiunto)
 *   cy_num  = Σ (lng_i + lng_{i+1}) * cross_i
 *   area    = 0.5 * sign * Σ cross_i
 *
 * Il centroide di un ring è: lat = cx_num / (6 * area)
 *
 * @return array{float,float,float}
 */
function analyticspro_centroid_ring(array $ring, float $sign): array
{
    $n = count($ring);
    if ($n < 3) {
        return [0.0, 0.0, 0.0];
    }

    // Traslazione all'origine del primo vertice per stabilità numerica
    $refLat = $ring[0]['lat'];
    $refLng = $ring[0]['lng'];

    $rawArea = 0.0;
    $cxNum   = 0.0;
    $cyNum   = 0.0;

    for ($i = 0; $i < $n; $i++) {
        $j    = ($i + 1) % $n;
        $lat0 = $ring[$i]['lat'] - $refLat;
        $lng0 = $ring[$i]['lng'] - $refLng;
        $lat1 = $ring[$j]['lat'] - $refLat;
        $lng1 = $ring[$j]['lng'] - $refLng;

        $cross    = $lat0 * $lng1 - $lat1 * $lng0;
        $rawArea += $cross;
        $cxNum   += ($lat0 + $lat1) * $cross;
        $cyNum   += ($lng0 + $lng1) * $cross;
    }

    $area = 0.5 * $sign * $rawArea;

    // Re-aggiungi l'offset di traslazione al numeratore centroide
    // centroid = (cx_num_translated + refLat * 6 * area) / (6 * area)
    // quindi cx_num = cx_num_translated + refLat * 6 * area
    // e restituiamo cx_num tale che final_centroid = (Σ cx_num) / (6 * Σ area)
    $cxNum *= $sign;
    $cyNum *= $sign;
    // aggiungi contributo offset: refLat * (2 * area) perché il fattore 6 verrà diviso fuori
    $cxNum += $refLat * (6.0 * $area);
    $cyNum += $refLng * (6.0 * $area);

    return [$cxNum, $cyNum, $area];
}

/**
 * Restituisce un punto sicuramente interno al poligono (materiale, non nel buco).
 *
 * Prima tenta il centroide; se cade fuori dall'esterno o dentro un buco,
 * usa una scanline orizzontale a metà latitudine del bbox per trovare
 * un punto certamente interno.
 *
 * @param  list<array{lat:float,lng:float}>  $exterior
 * @param  list<list<array{lat:float,lng:float}>>  $interiors
 * @return array{lat:float,lng:float}|null
 */
function analyticspro_interior_point(array $exterior, array $interiors = []): ?array
{
    if (count($exterior) < 3) {
        return null;
    }

    $centroid = analyticspro_centroid($exterior, $interiors);
    if ($centroid !== null) {
        $insideExt = analyticspro_point_in_polygon($centroid, $exterior);
        $inHole    = false;
        foreach ($interiors as $hole) {
            if (analyticspro_point_in_polygon($centroid, $hole)) {
                $inHole = true;
                break;
            }
        }
        if ($insideExt && !$inHole) {
            return $centroid;
        }
    }

    // Fallback: scanline a metà altezza del bbox.
    return analyticspro_scanline_interior_point($exterior, $interiors);
}

/**
 * Verifica se un punto è dentro un poligono (ray-casting).
 *
 * @param  array{lat:float,lng:float}  $point
 * @param  list<array{lat:float,lng:float}>  $ring
 */
function analyticspro_point_in_polygon(array $point, array $ring): bool
{
    $lat = $point['lat'];
    $lng = $point['lng'];
    $n   = count($ring);
    $inside = false;

    $j = $n - 1;
    for ($i = 0; $i < $n; $i++) {
        $xi = $ring[$i]['lat'];
        $yi = $ring[$i]['lng'];
        $xj = $ring[$j]['lat'];
        $yj = $ring[$j]['lng'];

        if ((($yi > $lng) !== ($yj > $lng)) &&
            ($lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi)) {
            $inside = !$inside;
        }
        $j = $i;
    }

    return $inside;
}

/**
 * Scanline a metà altitudine: restituisce il midpoint del primo segmento di intersezione.
 *
 * @param  list<array{lat:float,lng:float}>  $ring
 * @param  list<list<array{lat:float,lng:float}>>  $holes
 * @return array{lat:float,lng:float}|null
 */
function analyticspro_scanline_interior_point(array $ring, array $holes = []): ?array
{
    if (count($ring) < 3) {
        return null;
    }

    $lats = array_column($ring, 'lat');
    $minLat = min($lats);
    $maxLat = max($lats);

    // Prova più scanline a diverse altezze
    $scanLines = [0.5, 0.25, 0.75, 0.3, 0.6, 0.1, 0.9];

    foreach ($scanLines as $frac) {
        $midLat = $minLat + ($maxLat - $minLat) * $frac;
        $pt = analyticspro_scanline_at_lat($ring, $holes, $midLat);
        if ($pt !== null) {
            return $pt;
        }
    }

    // Fallback estremo: centroide aritmetico dei vertici
    $n = count($ring);
    $sumLat = 0.0;
    $sumLng = 0.0;
    foreach ($ring as $pt) {
        $sumLat += $pt['lat'];
        $sumLng += $pt['lng'];
    }
    return ['lat' => $sumLat / $n, 'lng' => $sumLng / $n];
}

/**
 * @return array{lat:float,lng:float}|null
 */
function analyticspro_scanline_at_lat(array $ring, array $holes, float $scanLat): ?array
{
    $intersections = [];
    $n = count($ring);
    $j = $n - 1;
    for ($i = 0; $i < $n; $i++) {
        $lat0 = $ring[$i]['lat'];
        $lng0 = $ring[$i]['lng'];
        $lat1 = $ring[$j]['lat'];
        $lng1 = $ring[$j]['lng'];

        if (($lat0 < $scanLat) !== ($lat1 < $scanLat)) {
            $t     = ($scanLat - $lat0) / ($lat1 - $lat0);
            $intersections[] = $lng0 + $t * ($lng1 - $lng0);
        }
        $j = $i;
    }

    if (count($intersections) < 2) {
        return null;
    }

    sort($intersections);

    // Cerca il primo intervallo non ostruito da buchi
    for ($k = 0; $k + 1 < count($intersections); $k += 2) {
        $midLng = ($intersections[$k] + $intersections[$k + 1]) / 2.0;
        $candidate = ['lat' => $scanLat, 'lng' => $midLng];

        $inHole = false;
        foreach ($holes as $hole) {
            if (analyticspro_point_in_polygon($candidate, $hole)) {
                $inHole = true;
                break;
            }
        }
        if (!$inHole) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Calcola la superficie in m² di un ring con proiezione equirettangolare locale.
 *
 * @param  list<array{lat:float,lng:float}>  $ring
 */
function analyticspro_ring_area_m2(array $ring): float
{
    $n = count($ring);
    if ($n < 3) {
        return 0.0;
    }

    // Centro del ring per la proiezione locale
    $lats = array_column($ring, 'lat');
    $midLat = array_sum($lats) / count($lats);

    $R = 6371000.0; // raggio medio Terra in m
    $cosLat = cos(deg2rad($midLat));

    $area = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $x0 = deg2rad($ring[$i]['lat']) * $R;
        $y0 = deg2rad($ring[$i]['lng']) * $R * $cosLat;
        $x1 = deg2rad($ring[$j]['lat']) * $R;
        $y1 = deg2rad($ring[$j]['lng']) * $R * $cosLat;
        $area += $x0 * $y1 - $x1 * $y0;
    }

    return abs($area / 2.0);
}

/**
 * Seleziona il ring esterno con area maggiore da un array di rings.
 *
 * @param  list<list<array{lat:float,lng:float}>>  $rings
 * @return list<array{lat:float,lng:float}>|null
 */
function analyticspro_largest_ring(array $rings): ?array
{
    if ($rings === []) {
        return null;
    }

    $best     = null;
    $bestArea = -1.0;
    foreach ($rings as $ring) {
        $a = analyticspro_ring_area_m2($ring);
        if ($a > $bestArea) {
            $bestArea = $a;
            $best     = $ring;
        }
    }

    return $best;
}
