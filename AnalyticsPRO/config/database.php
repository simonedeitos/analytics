<?php

declare(strict_types=1);

function analyticspro_env(string $key, ?string $default = null): ?string
{
    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}

function analyticspro_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = analyticspro_env('DB_HOST', '127.0.0.1');
    $port = analyticspro_env('DB_PORT', '3306');
    $name = analyticspro_env('DB_NAME', 'analyticspro');
    $user = analyticspro_env('DB_USER', 'root');
    $pass = analyticspro_env('DB_PASS', '');

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function analyticspro_postgis_db(): ?PDO
{
    static $pdo = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if ($pdo === null) {
        return null;
    }

    $host = analyticspro_env('POSTGIS_HOST');
    $db = analyticspro_env('POSTGIS_DB');
    $user = analyticspro_env('POSTGIS_USER');

    if (!$host || !$db || !$user) {
        $pdo = null;
        return null;
    }

    $port = analyticspro_env('POSTGIS_PORT', '5432');
    $pass = analyticspro_env('POSTGIS_PASS', '');
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $db);

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $exception) {
        $pdo = null;
    }

    return $pdo instanceof PDO ? $pdo : null;
}
