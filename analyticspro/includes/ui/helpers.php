<?php

declare(strict_types=1);

function analyticspro_ui_render(string $partial, array $vars = []): string
{
    $path = ANALYTICSPRO_ROOT . '/includes/ui/' . ltrim($partial, '/');
    if (!is_file($path)) {
        throw new RuntimeException('Partial UI non trovata: ' . $partial);
    }

    extract($vars, EXTR_SKIP);
    ob_start();
    include $path;
    return (string) ob_get_clean();
}

function analyticspro_ui_page_header(string $title, string $subtitle = '', string $actions = '', array $options = []): string
{
    return analyticspro_ui_render('page_header.php', [
        'title' => $title,
        'subtitle' => $subtitle,
        'actions' => $actions,
        'eyebrow' => (string) ($options['eyebrow'] ?? ''),
        'classes' => (string) ($options['classes'] ?? ''),
    ]);
}

function analyticspro_ui_kpi_card(array $options): string
{
    return analyticspro_ui_render('kpi_card.php', $options);
}

function analyticspro_ui_chart_card(array $options): string
{
    return analyticspro_ui_render('chart_card.php', $options);
}

function analyticspro_ui_empty_state(array $options): string
{
    return analyticspro_ui_render('empty_state.php', $options);
}

function analyticspro_ui_skeleton(array $options = []): string
{
    return analyticspro_ui_render('skeleton.php', $options);
}
