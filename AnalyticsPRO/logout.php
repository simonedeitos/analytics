<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
analyticspro_logout();
analyticspro_set_flash('success', 'Logout effettuato con successo.');
analyticspro_redirect('login.php');
