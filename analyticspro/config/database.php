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
    // DEPRECATED – cadastral geometry data is now stored in dedicated tables
    // (cadastral_comuni, cadastral_parcels, cadastral_parcel_verification) inside
    // the same MySQL applicative database.  This function is kept for
    // backwards-compatibility only and will always return null.
    // @see sql/cadastral_geometry.sql
    return null;
}
