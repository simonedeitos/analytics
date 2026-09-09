<?php
$title = (string) ($title ?? '');
$icon = (string) ($icon ?? 'bi-bar-chart');
$canvasId = (string) ($canvas_id ?? '');
$classes = trim((string) ($classes ?? ''));
$actions = (string) ($actions ?? '');
$body = (string) ($body ?? '');
$height = (string) ($height ?? '280');
?>
<section class="card border-0 shadow-sm ap-chart-card <?= analyticspro_h($classes) ?>">
    <div class="card-body">
        <div class="ap-chart-card-header">
            <div class="d-flex align-items-center gap-2">
                <span class="ap-chart-card-icon" aria-hidden="true"><i class="bi <?= analyticspro_h($icon) ?>"></i></span>
                <h2 class="ap-chart-card-title"><?= analyticspro_h($title) ?></h2>
            </div>
            <?php if ($actions !== ''): ?>
                <div><?= $actions ?></div>
            <?php endif; ?>
        </div>
        <div class="ap-chart-card-body" style="height:<?= analyticspro_h($height) ?>px">
            <?php if ($body !== ''): ?>
                <?= $body ?>
            <?php elseif ($canvasId !== ''): ?>
                <canvas id="<?= analyticspro_h($canvasId) ?>"></canvas>
            <?php endif; ?>
        </div>
    </div>
</section>
