<?php

declare(strict_types=1);

function analyticspro_page_matches(string $currentPage, array $pages): bool
{
    foreach ($pages as $page) {
        if ($currentPage === $page) {
            return true;
        }
    }
    return false;
}

function analyticspro_nav_items(?array $user = null, ?array $subuserPermissions = null): array
{
    $user ??= analyticspro_current_user();
    $subuserPermissions ??= ($user && analyticspro_is_subuser())
        ? analyticspro_get_subuser_permissions((int) $user['id'])
        : [];

    $pendingCount = analyticspro_is_admin() ? analyticspro_count_pending_registrations() : 0;

    return [
        [
            'label' => 'Panoramica',
            'items' => [
                [
                    'label' => 'Dashboard',
                    'icon' => 'bi-speedometer2',
                    'url' => analyticspro_base_url('dashboard.php'),
                    'page' => ['dashboard.php'],
                    'visible' => true,
                    'badge' => 0,
                ],
                [
                    'label' => 'Importa dati',
                    'icon' => 'bi-upload',
                    'url' => analyticspro_base_url('importa.php'),
                    'page' => ['importa.php'],
                    'visible' => !analyticspro_is_subuser() || !empty($subuserPermissions['can_import']),
                    'badge' => 0,
                ],
            ],
        ],
        [
            'label' => 'Territorio',
            'items' => [
                [
                    'label' => 'Mappa',
                    'icon' => 'bi-map',
                    'url' => analyticspro_base_url('mappa.php'),
                    'page' => ['mappa.php'],
                    'visible' => true,
                    'badge' => 0,
                ],
                [
                    'label' => 'Marker assegnati',
                    'icon' => 'bi-pin-map',
                    'url' => analyticspro_base_url('assegnati.php'),
                    'page' => ['assegnati.php'],
                    'visible' => true,
                    'badge' => 0,
                ],
            ],
        ],
        [
            'label' => 'Analisi',
            'visible' => !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_reports']) || !empty($subuserPermissions['can_view_analytics']),
            'items' => [
                [
                    'label' => 'Report in griglia',
                    'icon' => 'bi-table',
                    'url' => analyticspro_base_url('report.php'),
                    'page' => ['report.php'],
                    'visible' => !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_reports']),
                    'badge' => 0,
                ],
                [
                    'label' => 'Analitiche avanzate',
                    'icon' => 'bi-bar-chart-line',
                    'url' => analyticspro_base_url('analitiche.php'),
                    'page' => ['analitiche.php'],
                    'visible' => !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_analytics']),
                    'badge' => 0,
                ],
            ],
        ],
        [
            'label' => 'Account',
            'items' => [
                [
                    'label' => 'Subutenti',
                    'icon' => 'bi-people',
                    'url' => analyticspro_base_url('subutenti.php'),
                    'page' => ['subutenti.php'],
                    'visible' => analyticspro_is_main_user(),
                    'badge' => 0,
                ],
                [
                    'label' => 'Aiuto',
                    'icon' => 'bi-question-circle',
                    'url' => analyticspro_base_url('aiuto.php'),
                    'page' => ['aiuto.php'],
                    'visible' => true,
                    'badge' => 0,
                ],
            ],
        ],
        [
            'label' => 'Amministrazione',
            'visible' => analyticspro_is_admin(),
            'items' => [
                [
                    'label' => 'Amministrazione',
                    'icon' => 'bi-shield-check',
                    'url' => analyticspro_base_url('admin/index.php'),
                    'page' => ['admin/index.php', 'admin/registrazioni.php', 'admin/utenti.php', 'admin/smtp.php', 'admin/import_gml.php', 'admin/import_ade.php', 'admin/diagnostica_import.php', 'admin/diagnostica_enrichment.php'],
                    'visible' => analyticspro_is_admin(),
                    'badge' => $pendingCount,
                ],
            ],
        ],
    ];
}
