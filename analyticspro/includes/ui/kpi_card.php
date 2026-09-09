<?php
$icon = (string) ($icon ?? 'bi-bar-chart');
$label = (string) ($label ?? '');
$value = (string) ($value ?? '');
$delta = $delta ?? null;
$deltaLabel = (string) ($delta_label ?? 'vs periodo precedente');
$dataKey = (string) ($data_key ?? '');
$sparklineId = (string) ($sparkline_id ?? '');
$iconTone = (string) ($icon_tone ?? 'primary');
$classes = trim((string) ($classes ?? ''));
$deltaClass = 'neutral';
$deltaPrefix = '';
if (is_numeric($delta)) {
    if ((float) $delta > 0) {
        $deltaClass = 'positive';
        $deltaPrefix = '+';
    } elseif ((float) $delta < 0) {
        $deltaClass = 'negative';
    }
}
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
        <div class="d-flex align-items-center justify-content-between gap-3 mt-3">
            <div class="ap-kpi-delta ap-kpi-delta-<?= analyticspro_h($deltaClass) ?>"<?= $dataKey !== '' ? ' data-kpi-delta="' . analyticspro_h($dataKey) . '"' : '' ?>>
                <?php if (is_numeric($delta)): ?>
                    <?= analyticspro_h($deltaPrefix . number_format((float) $delta, 1, ',', '.')) ?>%
                <?php else: ?>
                    <?= analyticspro_ui_skeleton(['width' => '5rem', 'height' => '1rem']) ?>
                <?php endif; ?>
            </div>
            <?php if ($sparklineId !== ''): ?>
                <canvas id="<?= analyticspro_h($sparklineId) ?>" class="ap-kpi-sparkline" height="36" aria-label="Trend <?= analyticspro_h($label) ?>"></canvas>
            <?php endif; ?>
        </div>
        <div class="ap-kpi-meta"><?= analyticspro_h($deltaLabel) ?></div>
    </div>
</article>
