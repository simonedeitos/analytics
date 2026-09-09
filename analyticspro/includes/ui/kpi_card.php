<?php
$icon = (string) ($icon ?? 'bi-bar-chart');
$label = (string) ($label ?? '');
$value = (string) ($value ?? '');
$dataKey = (string) ($data_key ?? '');
$sparklineId = (string) ($sparkline_id ?? '');
$iconTone = (string) ($icon_tone ?? 'primary');
$classes = trim((string) ($classes ?? ''));
?>
<article class="card border-0 shadow-sm ap-kpi-card <?= analyticspro_h($classes) ?>">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-3">
            <div>
                <div class="ap-kpi-label"><?= analyticspro_h($label) ?></div>
                <div class="ap-kpi-value"<?= $dataKey !== '' ? ' data-kpi="' . analyticspro_h($dataKey) . '"' : '' ?>><?= $value !== '' ? analyticspro_h($value) : analyticspro_ui_skeleton(['width' => '7rem', 'height' => '2.5rem']) ?></div>
            </div>
            <span class="ap-kpi-icon ap-kpi-icon-<?= analyticspro_h($iconTone) ?>" aria-hidden="true">
                <i class="bi <?= analyticspro_h($icon) ?>"></i>
            </span>
        </div>
        <?php if ($sparklineId !== ''): ?>
            <div class="d-flex justify-content-end mt-3">
                <canvas id="<?= analyticspro_h($sparklineId) ?>" class="ap-kpi-sparkline" height="36" aria-label="Trend <?= analyticspro_h($label) ?>"></canvas>
            </div>
        <?php endif; ?>
    </div>
</article>
