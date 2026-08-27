<?php

declare(strict_types=1);

function analyticspro_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function analyticspro_verify_csrf(?string $token): void
{
    if (!is_string($token) || !hash_equals(analyticspro_csrf_token(), $token)) {
        throw new RuntimeException('Token CSRF non valido.');
    }
}
