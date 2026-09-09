<?php
$icon = (string) ($icon ?? 'bi-inbox');
$title = (string) ($title ?? 'Nessun dato disponibile');
$message = (string) ($message ?? '');
$cta = (string) ($cta ?? '');
$classes = trim((string) ($classes ?? ''));
?>
<div class="ap-empty-state <?= analyticspro_h($classes) ?>">
    <div class="ap-empty-state-icon" aria-hidden="true"><i class="bi <?= analyticspro_h($icon) ?>"></i></div>
    <h2 class="ap-empty-state-title"><?= analyticspro_h($title) ?></h2>
    <?php if ($message !== ''): ?>
        <p class="ap-empty-state-message mb-0"><?= analyticspro_h($message) ?></p>
    <?php endif; ?>
    <?php if ($cta !== ''): ?>
        <div class="mt-3"><?= $cta ?></div>
    <?php endif; ?>
</div>
