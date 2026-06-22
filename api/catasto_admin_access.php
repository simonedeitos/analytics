<?php
declare(strict_types=1);

function catastoEnforceAdminAccess(bool $jsonResponse = false): void
{
    $configuredToken = (string)(getenv('CATASTO_BUILD_TOKEN') ?: '');
    $providedToken = (string)($_SERVER['HTTP_X_CATASTO_ADMIN_TOKEN'] ?? $_REQUEST['token'] ?? '');

    if ($configuredToken !== '') {
        if (!hash_equals($configuredToken, $providedToken)) {
            catastoDenyAdminAccess($jsonResponse);
        }
        return;
    }

    $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!catastoIsPrivateOrLocalAddress($remoteAddr)) {
        catastoDenyAdminAccess($jsonResponse);
    }
}

function catastoIsPrivateOrLocalAddress(string $ip): bool
{
    if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)/', $ip) === 1;
    }

    return str_starts_with(strtolower($ip), 'fc') || str_starts_with(strtolower($ip), 'fd');
}

function catastoDenyAdminAccess(bool $jsonResponse): void
{
    if ($jsonResponse) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'Accesso negato: endpoint admin disponibile solo da rete locale o con token CATASTO_BUILD_TOKEN',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(403);
    exit('Accesso negato: questa pagina admin è disponibile solo da rete locale oppure con CATASTO_BUILD_TOKEN configurato.');
}
