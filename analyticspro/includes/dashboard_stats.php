<?php

declare(strict_types=1);

require_once ANALYTICSPRO_ROOT . '/includes/property_repository.php';

function analyticspro_dashboard_period_key(string $period): string
{
    $period = strtolower(trim($period));
    return match ($period) {
        'oggi', 'today' => 'today',
        '7', '7d', '7days', '7-giorni' => '7d',
        '30', '30d', '30days', '30-giorni' => '30d',
        'anno', 'year', '1y' => 'year',
        default => 'all',
    };
}

function analyticspro_dashboard_period_bounds(string $period, ?DateTimeImmutable $now = null): array
{
    $period = analyticspro_dashboard_period_key($period);
    $now ??= new DateTimeImmutable('now');
    $end = $now;

    if ($period === 'all') {
        return [
            'period' => 'all',
            'current_start' => null,
            'current_end' => $end,
            'previous_start' => null,
            'previous_end' => null,
        ];
    }

    if ($period === 'today') {
        $currentStart = $now->setTime(0, 0, 0);
        $previousStart = $currentStart->modify('-1 day');
        $previousEnd = $currentStart->modify('-1 second');
    } elseif ($period === '7d') {
        $currentStart = $now->modify('-6 days')->setTime(0, 0, 0);
        $previousStart = $currentStart->modify('-7 days');
        $previousEnd = $currentStart->modify('-1 second');
    } elseif ($period === '30d') {
        $currentStart = $now->modify('-29 days')->setTime(0, 0, 0);
        $previousStart = $currentStart->modify('-30 days');
        $previousEnd = $currentStart->modify('-1 second');
    } else {
        $currentStart = $now->modify('-364 days')->setTime(0, 0, 0);
        $previousStart = $currentStart->modify('-365 days');
        $previousEnd = $currentStart->modify('-1 second');
    }

    return [
        'period' => $period,
        'current_start' => $currentStart,
        'current_end' => $end,
        'previous_start' => $previousStart,
        'previous_end' => $previousEnd,
    ];
}

function analyticspro_dashboard_parse_datetime(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($value);
    } catch (Throwable) {
        return null;
    }
}

function analyticspro_dashboard_matches_dimension(array $property, ?string $comune, ?string $category): bool
{
    if ($comune !== null && $comune !== '' && strcasecmp(trim((string) ($property['comune'] ?? '')), $comune) !== 0) {
        return false;
    }
    if ($category !== null && $category !== '' && strcasecmp(trim((string) ($property['categoria'] ?? '')), $category) !== 0) {
        return false;
    }
    return true;
}

function analyticspro_dashboard_filter_properties(array $properties, array $options = []): array
{
    $comune = trim((string) ($options['comune'] ?? ''));
    $category = trim((string) ($options['category'] ?? ''));
    $bounds = $options['bounds'] ?? analyticspro_dashboard_period_bounds((string) ($options['period'] ?? 'all'));
    $currentStart = $bounds['current_start'] ?? null;
    $currentEnd = $bounds['current_end'] ?? null;

    return array_values(array_filter($properties, static function (array $property) use ($comune, $category, $currentStart, $currentEnd): bool {
        if (!analyticspro_dashboard_matches_dimension($property, $comune, $category)) {
            return false;
        }
        if (!$currentStart instanceof DateTimeImmutable || !$currentEnd instanceof DateTimeImmutable) {
            return true;
        }
        $createdAt = analyticspro_dashboard_parse_datetime((string) ($property['created_at'] ?? ''));
        if (!$createdAt) {
            return false;
        }
        return $createdAt >= $currentStart && $createdAt <= $currentEnd;
    }));
}

function analyticspro_dashboard_previous_properties(array $properties, array $options = []): array
{
    $comune = trim((string) ($options['comune'] ?? ''));
    $category = trim((string) ($options['category'] ?? ''));
    $bounds = $options['bounds'] ?? analyticspro_dashboard_period_bounds((string) ($options['period'] ?? 'all'));
    $previousStart = $bounds['previous_start'] ?? null;
    $previousEnd = $bounds['previous_end'] ?? null;

    if (!$previousStart instanceof DateTimeImmutable || !$previousEnd instanceof DateTimeImmutable) {
        return [];
    }

    return array_values(array_filter($properties, static function (array $property) use ($comune, $category, $previousStart, $previousEnd): bool {
        if (!analyticspro_dashboard_matches_dimension($property, $comune, $category)) {
            return false;
        }
        $createdAt = analyticspro_dashboard_parse_datetime((string) ($property['created_at'] ?? ''));
        if (!$createdAt) {
            return false;
        }
        return $createdAt >= $previousStart && $createdAt <= $previousEnd;
    }));
}

function analyticspro_dashboard_count_owners(array $properties): int
{
    return array_reduce($properties, static function (int $sum, array $property): int {
        return $sum + count($property['owners'] ?? []);
    }, 0);
}

function analyticspro_dashboard_count_phone_owners(array $properties, bool $canViewPhone): int
{
    if (!$canViewPhone) {
        return 0;
    }
    return array_reduce($properties, static function (int $sum, array $property): int {
        $owners = $property['owners'] ?? [];
        $count = 0;
        foreach ($owners as $owner) {
            if (trim((string) ($owner['telefono'] ?? '')) !== '') {
                $count++;
            }
        }
        return $sum + $count;
    }, 0);
}

function analyticspro_dashboard_count_email_owners(array $properties): int
{
    return array_reduce($properties, static function (int $sum, array $property): int {
        $owners = $property['owners'] ?? [];
        $count = 0;
        foreach ($owners as $owner) {
            if (trim((string) ($owner['email'] ?? '')) !== '') {
                $count++;
            }
        }
        return $sum + $count;
    }, 0);
}

function analyticspro_dashboard_count_piva_owners(array $properties): int
{
    return array_reduce($properties, static function (int $sum, array $property): int {
        $owners = $property['owners'] ?? [];
        $count = 0;
        foreach ($owners as $owner) {
            if (($owner['tipo'] ?? '') === 'azienda') {
                $count++;
            }
        }
        return $sum + $count;
    }, 0);
}

function analyticspro_dashboard_percent_delta(int $current, int $previous): ?float
{
    if ($previous === 0) {
        return $current === 0 ? 0.0 : 100.0;
    }
    return round((($current - $previous) / $previous) * 100, 1);
}

function analyticspro_dashboard_sparkline(array $values): array
{
    return array_map(static fn ($value): int => (int) $value, array_values($values));
}

function analyticspro_dashboard_age_group(?string $date): string
{
    $dt = analyticspro_dashboard_parse_datetime($date);
    if (!$dt) {
        return 'N/D';
    }
    $age = (new DateTimeImmutable('today'))->diff($dt)->y;
    return match (true) {
        $age < 30 => '0-29',
        $age < 45 => '30-44',
        $age < 60 => '45-59',
        $age < 75 => '60-74',
        default => '75+',
    };
}

function analyticspro_dashboard_series_assoc(array $items, int $limit = 0): array
{
    arsort($items);
    if ($limit > 0) {
        $items = array_slice($items, 0, $limit, true);
    }
    return [
        'labels' => array_keys($items),
        'values' => array_values($items),
    ];
}

function analyticspro_dashboard_series_with_others(array $items, int $limit, string $othersLabel = 'Altri'): array
{
    arsort($items);
    if ($limit <= 0 || count($items) <= $limit) {
        return analyticspro_dashboard_series_assoc($items);
    }

    $top = array_slice($items, 0, $limit, true);
    $remaining = array_slice($items, $limit, null, true);
    $others = array_sum(array_values($remaining));
    if ($others > 0) {
        $top[$othersLabel] = (int) $others;
    }

    return [
        'labels' => array_keys($top),
        'values' => array_values($top),
    ];
}

function analyticspro_dashboard_build_stats(array $properties, array $assignedProperties, array $options = []): array
{
    $bounds = $options['bounds'] ?? analyticspro_dashboard_period_bounds((string) ($options['period'] ?? 'all'));
    $currentProperties = analyticspro_dashboard_filter_properties($properties, $options + ['bounds' => $bounds]);
    $previousProperties = analyticspro_dashboard_previous_properties($properties, $options + ['bounds' => $bounds]);
    $currentAssigned = analyticspro_dashboard_filter_properties($assignedProperties, $options + ['bounds' => $bounds]);
    $previousAssigned = analyticspro_dashboard_previous_properties($assignedProperties, $options + ['bounds' => $bounds]);
    $canViewPhone = (bool) ($options['can_view_phone'] ?? false);
    $canViewAnalytics = (bool) ($options['can_view_analytics'] ?? false);
    $isSubuser = (bool) ($options['is_subuser'] ?? false);
    $mapLimit = max(10, (int) ($options['map_limit'] ?? 250));

    $owners = [];
    foreach ($currentProperties as $property) {
        foreach ($property['owners'] ?? [] as $owner) {
            $owners[] = $owner;
        }
    }

    $contacts = [
        'Con telefono' => 0,
        'Con email' => 0,
        'Senza contatti' => 0,
    ];
    $genders = [];
    $ages = [];
    $comuni = [];
    $categories = [];
    $ownership = [];

    foreach ($owners as $owner) {
        $hasPhone = $canViewPhone && trim((string) ($owner['telefono'] ?? '')) !== '';
        $hasEmail = trim((string) ($owner['email'] ?? '')) !== '';
        if ($hasPhone) {
            $contacts['Con telefono']++;
        }
        if ($hasEmail) {
            $contacts['Con email']++;
        }
        if (!$hasPhone && !$hasEmail) {
            $contacts['Senza contatti']++;
        }
        $genderKey = trim((string) ($owner['genere'] ?? '')) ?: 'N/D';
        $genders[$genderKey] = ($genders[$genderKey] ?? 0) + 1;
        $ageKey = analyticspro_dashboard_age_group($owner['data_nascita'] ?? null);
        $ages[$ageKey] = ($ages[$ageKey] ?? 0) + 1;
    }

    $mapPoints = [];
    foreach ($currentProperties as $property) {
        $comuneKey = trim((string) ($property['comune'] ?? '')) ?: 'N/D';
        $comuni[$comuneKey] = ($comuni[$comuneKey] ?? 0) + 1;
        $categoryKey = trim((string) ($property['categoria'] ?? '')) ?: 'N/D';
        $categories[$categoryKey] = ($categories[$categoryKey] ?? 0) + 1;
        $ownershipKey = trim((string) ($property['titolarita'] ?? '')) ?: 'N/D';
        $ownership[$ownershipKey] = ($ownership[$ownershipKey] ?? 0) + 1;

        $lat = isset($property['lat']) ? (float) $property['lat'] : null;
        $lng = isset($property['lng']) ? (float) $property['lng'] : null;
        if ($lat && $lng && count($mapPoints) < $mapLimit) {
            $mapPoints[] = [
                'lat' => $lat,
                'lng' => $lng,
                'categoria' => $property['categoria'] ?? '',
                'comune' => $property['comune'] ?? '',
                'provincia' => $property['provincia'] ?? '',
            ];
        }
    }

    $currentKpis = [
        'properties' => count($currentProperties),
        'owners' => analyticspro_dashboard_count_owners($currentProperties),
        'phones' => analyticspro_dashboard_count_phone_owners($currentProperties, $canViewPhone),
        'email' => analyticspro_dashboard_count_email_owners($currentProperties),
        'piva' => analyticspro_dashboard_count_piva_owners($currentProperties),
        'assigned' => $isSubuser
            ? count($currentAssigned)
            : count(array_filter($currentProperties, static fn (array $property): bool => !empty($property['assignments']))),
    ];
    $previousKpis = [
        'properties' => count($previousProperties),
        'owners' => analyticspro_dashboard_count_owners($previousProperties),
        'phones' => analyticspro_dashboard_count_phone_owners($previousProperties, $canViewPhone),
        'email' => analyticspro_dashboard_count_email_owners($previousProperties),
        'piva' => analyticspro_dashboard_count_piva_owners($previousProperties),
        'assigned' => $isSubuser
            ? count($previousAssigned)
            : count(array_filter($previousProperties, static fn (array $property): bool => !empty($property['assignments']))),
    ];

    return [
        'kpis' => [
            'properties' => ['value' => $currentKpis['properties'], 'delta' => analyticspro_dashboard_percent_delta($currentKpis['properties'], $previousKpis['properties']), 'sparkline' => analyticspro_dashboard_sparkline([$previousKpis['properties'], $currentKpis['properties']])],
            'owners' => ['value' => $currentKpis['owners'], 'delta' => analyticspro_dashboard_percent_delta($currentKpis['owners'], $previousKpis['owners']), 'sparkline' => analyticspro_dashboard_sparkline([$previousKpis['owners'], $currentKpis['owners']])],
            'phones' => ['value' => $currentKpis['phones'], 'delta' => analyticspro_dashboard_percent_delta($currentKpis['phones'], $previousKpis['phones']), 'sparkline' => analyticspro_dashboard_sparkline([$previousKpis['phones'], $currentKpis['phones']])],
            'email' => ['value' => $currentKpis['email'], 'delta' => analyticspro_dashboard_percent_delta($currentKpis['email'], $previousKpis['email']), 'sparkline' => analyticspro_dashboard_sparkline([$previousKpis['email'], $currentKpis['email']])],
            'piva' => ['value' => $currentKpis['piva'], 'delta' => analyticspro_dashboard_percent_delta($currentKpis['piva'], $previousKpis['piva']), 'sparkline' => analyticspro_dashboard_sparkline([$previousKpis['piva'], $currentKpis['piva']])],
            'assigned' => ['value' => $currentKpis['assigned'], 'delta' => analyticspro_dashboard_percent_delta($currentKpis['assigned'], $previousKpis['assigned']), 'sparkline' => analyticspro_dashboard_sparkline([$previousKpis['assigned'], $currentKpis['assigned']])],
        ],
        'series' => $canViewAnalytics ? [
            'contacts' => analyticspro_dashboard_series_assoc(array_filter($contacts, static fn (int $value): bool => $value > 0)),
            'gender' => analyticspro_dashboard_series_assoc($genders),
            'age' => analyticspro_dashboard_series_assoc($ages),
            'comune' => analyticspro_dashboard_series_assoc($comuni, 10),
            'comune_distribution' => analyticspro_dashboard_series_with_others($comuni, 20),
            'categoria' => analyticspro_dashboard_series_assoc($categories),
            'titolarita' => analyticspro_dashboard_series_assoc($ownership),
        ] : [],
        'map_points' => $mapPoints,
        'filters' => [
            'period' => $bounds['period'],
            'comune' => (string) ($options['comune'] ?? ''),
            'category' => (string) ($options['category'] ?? ''),
        ],
        'meta' => [
            'can_view_phone' => $canViewPhone,
            'can_view_analytics' => $canViewAnalytics,
            'properties_total' => count($properties),
            'map_points_total' => count($mapPoints),
        ],
    ];
}

function analyticspro_fetch_dashboard_stats(array $user, array $options = []): array
{
    $allPayload = analyticspro_fetch_properties_payload($user, 'all', null);
    $assignedPayload = analyticspro_fetch_properties_payload($user, 'assigned', null);

    $tenantId = analyticspro_current_tenant_id();
    $pdo = analyticspro_db();
    $importsSql = "SELECT b.id, b.filename, b.status, b.processed_rows, b.total_rows, b.created_at, b.completed_at, CONCAT(u.nome, ' ', u.cognome) AS uploaded_by_name FROM import_batches b JOIN users u ON u.id = b.uploaded_by";
    $activitySql = "SELECT p.id, p.comune, p.provincia, p.foglio, p.particella, p.updated_at, CONCAT(u.nome, ' ', u.cognome) AS tenant_name FROM properties p JOIN users u ON u.id = p.user_id";
    $params = [];
    if ($tenantId !== null) {
        $importsSql .= ' WHERE b.user_id = :tenant_id';
        $activitySql .= ' WHERE p.user_id = :tenant_id';
        $params['tenant_id'] = $tenantId;
    }
    $importsSql .= ' ORDER BY b.created_at DESC LIMIT 5';
    $activitySql .= ' ORDER BY p.updated_at DESC LIMIT 5';

    $importsStmt = $pdo->prepare($importsSql);
    $importsStmt->execute($params);
    $activityStmt = $pdo->prepare($activitySql);
    $activityStmt->execute($params);

    $stats = analyticspro_dashboard_build_stats(
        $allPayload['properties'] ?? [],
        $assignedPayload['properties'] ?? [],
        $options + [
            'can_view_phone' => analyticspro_tenant_phone_visibility($tenantId),
            'can_view_analytics' => !analyticspro_is_subuser() || !empty(analyticspro_get_subuser_permissions((int) $user['id'])['can_view_analytics']),
            'is_subuser' => analyticspro_is_subuser(),
        ]
    );

    $stats['recent_imports'] = $importsStmt->fetchAll() ?: [];
    $stats['recent_activity'] = $activityStmt->fetchAll() ?: [];
    $stats['subusers'] = $allPayload['subusers'] ?? [];

    return $stats;
}
