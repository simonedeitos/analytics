<?php
$currentPage = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
$adminPages = [
    'index.php'        => ['icon' => 'bi-speedometer2',   'label' => 'Panoramica'],
    'registrazioni.php'=> ['icon' => 'bi-person-check',   'label' => 'Registrazioni'],
    'utenti.php'       => ['icon' => 'bi-people',          'label' => 'Utenti'],
    'smtp.php'         => ['icon' => 'bi-envelope-gear',   'label' => 'SMTP'],
    'import_ade.php'   => ['icon' => 'bi-cloud-upload',    'label' => 'Import ADE'],
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
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
