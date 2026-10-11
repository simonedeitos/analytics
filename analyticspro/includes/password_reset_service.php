<?php

declare(strict_types=1);

const ANALYTICSPRO_PASSWORD_RESET_MESSAGE = "Se l'indirizzo email è registrato, riceverai a breve un'email con le istruzioni per reimpostare la password.";
const ANALYTICSPRO_PASSWORD_RESET_INVALID = 'Il link non è valido, è scaduto o è già stato utilizzato.';

function analyticspro_create_password_reset_token(PDO $pdo, int $userId): ?string
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Reset non disponibile durante un’altra transazione.');
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND status = 'active' FOR UPDATE");
        $stmt->execute(['id' => $userId]);
        if (!$stmt->fetch()) {
            $pdo->commit();
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);
        $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR))')
            ->execute(['user_id' => $userId, 'token_hash' => hash('sha256', $token)]);
        $pdo->commit();
        return $token;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function analyticspro_request_password_reset(string $email): string
{
    try {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = analyticspro_find_user_by_email($email);
            if ($user && ($user['status'] ?? '') === 'active') {
                // Use the configured URL, never the request Host header, for secret links.
                $url = analyticspro_base_url('reset_password.php');
                if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)) {
                    throw new RuntimeException('APP_URL non configurato.');
                }
                $token = analyticspro_create_password_reset_token(analyticspro_db(), (int) $user['id']);
                if ($token !== null) {
                    $url .= '?token=' . rawurlencode($token);
                    $html = sprintf(
                        '<p>Hai richiesto di reimpostare la password di easyradar.</p><p><a href="%s">Imposta una nuova password</a></p><p>Il link è valido per un’ora e può essere usato una sola volta. Se non hai richiesto il reset, ignora questa email.</p>',
                        analyticspro_h($url)
                    );
                    if (!analyticspro_send_email((string) $user['email'], 'Reimposta la password easyradar', $html, "Imposta una nuova password: {$url}\nIl link scade tra un’ora. Se non hai richiesto il reset, ignora questa email.")) {
                        error_log('AnalyticsPRO: invio email recupero password non riuscito.');
                    }
                }
            }
        }
    } catch (Throwable $exception) {
        error_log('AnalyticsPRO: richiesta recupero password non riuscita.');
    }
    return ANALYTICSPRO_PASSWORD_RESET_MESSAGE;
}

function analyticspro_find_password_reset_token(PDO $pdo, string $token): ?array
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT t.id, t.user_id FROM password_reset_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = :token_hash AND t.used_at IS NULL AND t.expires_at > NOW() AND u.status = 'active' LIMIT 1");
    $stmt->execute(['token_hash' => hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

function analyticspro_reset_password(PDO $pdo, string $token, string $password, string $confirm): void
{
    if (strlen($password) < 8) {
        throw new RuntimeException('La nuova password deve contenere almeno 8 caratteri.');
    }
    if ($password !== $confirm) {
        throw new RuntimeException('Le password non coincidono.');
    }
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Reset non disponibile durante un’altra transazione.');
    }
    $pdo->beginTransaction();
    try {
        $reset = analyticspro_find_password_reset_token($pdo, $token);
        if (!$reset) {
            throw new RuntimeException(ANALYTICSPRO_PASSWORD_RESET_INVALID);
        }
        // Lock the user first, as in login and token generation, then recheck the token.
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND status = 'active' FOR UPDATE");
        $stmt->execute(['id' => (int) $reset['user_id']]);
        if (!$stmt->fetch()) {
            throw new RuntimeException(ANALYTICSPRO_PASSWORD_RESET_INVALID);
        }
        $stmt = $pdo->prepare('SELECT id FROM password_reset_tokens
            WHERE id = :id AND user_id = :user_id AND token_hash = :token_hash
            AND used_at IS NULL AND expires_at > NOW() FOR UPDATE');
        $stmt->execute(['id' => $reset['id'], 'user_id' => $reset['user_id'], 'token_hash' => hash('sha256', $token)]);
        if (!$stmt->fetch()) {
            throw new RuntimeException(ANALYTICSPRO_PASSWORD_RESET_INVALID);
        }
        $pdo->prepare('UPDATE users SET password_hash = :password_hash, must_change_password = 0,
            remember_token = NULL, remember_token_expires_at = NULL WHERE id = :id')
            ->execute(['password_hash' => password_hash($password, PASSWORD_BCRYPT), 'id' => $reset['user_id']]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL')
            ->execute(['user_id' => $reset['user_id']]);
        $pdo->prepare('DELETE FROM user_sessions WHERE user_id = :user_id')
            ->execute(['user_id' => $reset['user_id']]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
