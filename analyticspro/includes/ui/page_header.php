<?php
$classes = trim((string) ($classes ?? ''));
?>
<div class="ap-page-header <?= analyticspro_h($classes) ?>">
    <div>
        <?php if (!empty($eyebrow)): ?>
            <div class="ap-page-eyebrow"><?= analyticspro_h((string) $eyebrow) ?></div>
        <?php endif; ?>
        <h1 class="ap-page-title"><?= analyticspro_h((string) $title) ?></h1>
        <?php if ($subtitle !== ''): ?>
            <p class="ap-page-subtitle mb-0"><?= analyticspro_h((string) $subtitle) ?></p>
        <?php endif; ?>
    </div>
    <?php if ($actions !== ''): ?>
        <div class="ap-page-actions">
            <?= $actions ?>
        </div>
    <?php endif; ?>
</div>
