<?php

declare(strict_types=1);

function analyticspro_current_user(): ?array
{
    return $_SESSION['analyticspro_user'] ?? null;
}

function analyticspro_set_current_user(array $user): void
{
    $_SESSION['analyticspro_user'] = $user;
}

function analyticspro_require_auth(): void
{
    if (!analyticspro_current_user()) {
        analyticspro_redirect('login.php');
    }
}

function analyticspro_api_require_auth(): array
{
    $user = analyticspro_current_user();
    if (!$user) {
        analyticspro_json(['ok' => false, 'error' => 'Sessione scaduta. Effettua nuovamente il login.'], 401);
    }
    return $user;
}

function analyticspro_require_guest(): void
{
    if (analyticspro_current_user()) {
        analyticspro_redirect('dashboard.php');
    }
}

function analyticspro_find_user_by_email(string $email): ?array
{
    $stmt = analyticspro_db()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => mb_strtolower(trim($email), 'UTF-8')]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function analyticspro_login(array $user, bool $remember = false): void
{
    session_regenerate_id(true);
    analyticspro_set_current_user($user);

    $expiresAt = (new DateTimeImmutable('+10 hours'))->format('Y-m-d H:i:s');
    $stmt = analyticspro_db()->prepare('REPLACE INTO user_sessions (id, user_id, ip_address, user_agent, expires_at) VALUES (:id, :user_id, :ip_address, :user_agent, :expires_at)');
    $stmt->execute([
        'id' => session_id(),
        'user_id' => $user['id'],
        'ip_address' => analyticspro_client_ip(),
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'expires_at' => $expiresAt,
    ]);

    $clearRemember = analyticspro_db()->prepare('UPDATE users SET remember_token = NULL, remember_token_expires_at = NULL WHERE id = :id');
    $clearRemember->execute(['id' => $user['id']]);

    if ($remember) {
        $rawToken = bin2hex(random_bytes(32));
        $hash = password_hash($rawToken, PASSWORD_BCRYPT);
        $stmt = analyticspro_db()->prepare('UPDATE users SET remember_token = :token, remember_token_expires_at = :expires_at WHERE id = :id');
        $stmt->execute([
            'token' => $hash,
            'expires_at' => $expiresAt,
            'id' => $user['id'],
        ]);
        setcookie('analyticspro_remember', $user['id'] . ':' . $rawToken, [
            'expires' => strtotime($expiresAt),
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function analyticspro_logout(): void
{
    $user = analyticspro_current_user();
    if ($user) {
        $stmt = analyticspro_db()->prepare('DELETE FROM user_sessions WHERE id = :id');
        $stmt->execute(['id' => session_id()]);
        $stmt = analyticspro_db()->prepare('UPDATE users SET remember_token = NULL, remember_token_expires_at = NULL WHERE id = :id');
        $stmt->execute(['id' => $user['id']]);
    }

    setcookie('analyticspro_remember', '', time() - 3600, '/');
    $_SESSION = [];
    session_destroy();
}

function analyticspro_attempt_remember_login(): void
{
    if (analyticspro_current_user() || empty($_COOKIE['analyticspro_remember'])) {
        return;
    }

    [$userId, $token] = array_pad(explode(':', (string) $_COOKIE['analyticspro_remember'], 2), 2, null);
    if (!$userId || !$token) {
        return;
    }

    $stmt = analyticspro_db()->prepare("SELECT * FROM users WHERE id = :id AND status = 'active' AND remember_token_expires_at >= NOW() LIMIT 1");
    $stmt->execute(['id' => (int) $userId]);
    $user = $stmt->fetch();
    if (!$user || empty($user['remember_token']) || !password_verify($token, $user['remember_token'])) {
        return;
    }

    analyticspro_login($user, false);
}
