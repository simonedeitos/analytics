<?php

declare(strict_types=1);

if (defined('ANALYTICSPRO_BOOTSTRAPPED')) {
    return;
}
define('ANALYTICSPRO_BOOTSTRAPPED', true);
define('ANALYTICSPRO_ROOT', dirname(__DIR__));

require_once ANALYTICSPRO_ROOT . '/includes/functions.php';
analyticspro_load_env(ANALYTICSPRO_ROOT . '/.env');
date_default_timezone_set('Europe/Rome');

session_name('analyticspro_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once ANALYTICSPRO_ROOT . '/config/database.php';
require_once ANALYTICSPRO_ROOT . '/includes/csrf.php';
require_once ANALYTICSPRO_ROOT . '/config/encryption.php';
require_once ANALYTICSPRO_ROOT . '/includes/mailer.php';
require_once ANALYTICSPRO_ROOT . '/includes/importer.php';
require_once ANALYTICSPRO_ROOT . '/includes/auth.php';

analyticspro_attempt_remember_login();
