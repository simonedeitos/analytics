<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

if (analyticspro_current_user()) {
    analyticspro_redirect('dashboard.php');
}

analyticspro_redirect('login.php');
