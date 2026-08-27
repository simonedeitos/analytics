<?php

declare(strict_types=1);

function analyticspro_encryption_key(): string
{
    static $key = null;

    if (is_string($key) && $key !== '') {
        return $key;
    }

    $configKey = analyticspro_system_config('encryption_key');
    $bootstrapKey = analyticspro_env('APP_BOOTSTRAP_ENCRYPTION_KEY', '');
    $raw = $configKey ?: $bootstrapKey;

    if ($raw === '') {
        throw new RuntimeException('Chiave di cifratura non configurata. Imposta APP_BOOTSTRAP_ENCRYPTION_KEY o system_config.encryption_key.');
    }

    $key = hash('sha256', $raw, true);
    return $key;
}

function analyticspro_encrypt(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    $iv = random_bytes(16);
    $cipher = openssl_encrypt($value, 'aes-256-cbc', analyticspro_encryption_key(), OPENSSL_RAW_DATA, $iv);

    if ($cipher === false) {
        throw new RuntimeException('Impossibile cifrare il dato.');
    }

    return $iv . $cipher;
}

function analyticspro_decrypt(?string $payload): ?string
{
    if ($payload === null || $payload === '') {
        return null;
    }

    $iv = substr($payload, 0, 16);
    $cipher = substr($payload, 16);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', analyticspro_encryption_key(), OPENSSL_RAW_DATA, $iv);

    return $plain === false ? null : $plain;
}

function analyticspro_hash(?string $value): ?string
{
    $normalized = analyticspro_normalize_hash_value($value);
    return $normalized === null ? null : hash('sha256', $normalized);
}
