<?php
$currentPage = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
$pendingCount = analyticspro_count_pending_registrations();
$adminPages = [
    'index.php'        => ['icon' => 'bi-speedometer2',   'label' => 'Panoramica',     'badge' => 0],
    'registrazioni.php'=> ['icon' => 'bi-person-check',   'label' => 'Registrazioni',  'badge' => $pendingCount],
    'utenti.php'       => ['icon' => 'bi-people',          'label' => 'Utenti',         'badge' => 0],
    'smtp.php'         => ['icon' => 'bi-envelope-gear',   'label' => 'SMTP',           'badge' => 0],
];
?>
<nav class="mb-4">
    <ul class="nav nav-pills ap-admin-subnav flex-wrap gap-1">
        <?php foreach ($adminPages as $file => $meta): ?>
            <li class="nav-item">
                <a class="nav-link<?= $currentPage === $file ? ' active' : '' ?>"
                   href="<?= analyticspro_h(analyticspro_base_url('admin/' . $file)) ?>">
                    <i class="bi <?= analyticspro_h($meta['icon']) ?> me-1"></i>
                    <?= analyticspro_h($meta['label']) ?>
                    <?php if ($meta['badge'] > 0): ?>
                        <span class="badge rounded-pill bg-danger ms-1"><?= (int) $meta['badge'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
