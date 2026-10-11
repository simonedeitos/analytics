<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/password_reset_service.php';

final class PasswordResetTestPdo extends PDO
{
    public array $queries = [];
    public ?string $failOn = null;
    public bool $rolledBack = false;
    public mixed $onUserLock = null;

    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, role TEXT, status TEXT,
            password_hash TEXT, must_change_password INTEGER, remember_token TEXT, remember_token_expires_at TEXT);
            CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER,
            token_hash TEXT UNIQUE, expires_at TEXT, used_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
            CREATE TABLE user_sessions (id TEXT, user_id INTEGER, expires_at TEXT);
            INSERT INTO users VALUES (1, "user@example.test", "user", "active", "old", 1, "remember", "2099-01-01"),
                (2, "subuser@example.test", "subuser", "active", "old", 1, "remember", "2099-01-01"),
                (3, "admin@example.test", "admin", "active", "old", 1, "remember", "2099-01-01"),
                (4, "pending@example.test", "user", "pending", "old", 1, NULL, NULL),
                (5, "disabled@example.test", "subuser", "disabled", "old", 1, NULL, NULL);
            INSERT INTO user_sessions VALUES ("first", 1, "2099-01-01"), ("second", 1, "2099-01-01"),
                ("other", 2, "2099-01-01");');
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        if ($this->failOn !== null && str_contains($query, $this->failOn)) {
            throw new RuntimeException('Injected database failure');
        }
        if (str_contains($query, 'FROM users') && str_contains($query, 'FOR UPDATE') && $this->onUserLock !== null) {
            $callback = $this->onUserLock;
            $this->onUserLock = null;
            $callback($this);
        }
        return parent::prepare(str_replace(
            ['DATE_ADD(NOW(), INTERVAL 1 HOUR)', 'NOW()', ' FOR UPDATE'],
            ["datetime('now', '+1 hour')", 'CURRENT_TIMESTAMP', ''],
            $query
        ), $options);
    }

    public function commit(): bool
    {
        if ($this->failOn === 'COMMIT') {
            throw new RuntimeException('Injected commit failure');
        }
        return parent::commit();
    }

    public function rollBack(): bool
    {
        $this->rolledBack = true;
        return parent::rollBack();
    }
}

function analyticspro_db(): PDO { return $GLOBALS['resetPdo']; }
function analyticspro_env(string $key, ?string $default = null): ?string
{
    return $key === 'APP_URL' ? $GLOBALS['resetAppUrl'] : $default;
}
function analyticspro_send_email(string $to, string $subject, string $html, ?string $text = null): bool
{
    $GLOBALS['resetEmails'][] = compact('to', 'subject', 'html', 'text');
    if ($GLOBALS['resetMailThrows']) {
        throw new RuntimeException('Injected SMTP failure');
    }
    return $GLOBALS['resetMailResult'];
}

$checks = 0;
function reset_check(bool $condition, string $message): void
{
    $GLOBALS['checks']++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}
function reset_reject(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        reset_check(true, $message);
        return;
    }
    reset_check(false, $message);
}
function reset_snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM password_reset_tokens ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM user_sessions ORDER BY id')->fetchAll(),
    ];
}

$resetAppUrl = 'https://example.test/analyticspro';
$resetMailResult = true;
$resetMailThrows = false;
$resetEmails = [];
$resetPdo = new PasswordResetTestPdo();
foreach (['user', 'subuser', 'admin'] as $role) {
    reset_check(analyticspro_request_password_reset("{$role}@example.test") === ANALYTICSPRO_PASSWORD_RESET_MESSAGE, "{$role} gets generic response");
    $mail = end($resetEmails);
    reset_check($mail['to'] === "{$role}@example.test", "{$role} receives email");
    preg_match('/token=([a-f0-9]{64})/', $mail['html'], $matches);
    $token = $matches[1] ?? '';
    reset_check(strlen($token) === 64 && !str_contains($mail['html'], '&id='), 'secret-only link');
    $row = $resetPdo->query('SELECT * FROM password_reset_tokens ORDER BY id DESC LIMIT 1')->fetch();
    reset_check($row['token_hash'] === hash('sha256', $token) && $row['token_hash'] !== $token, 'only hash stored');
    reset_check(abs(strtotime($row['expires_at'] . ' UTC') - time() - 3600) <= 2, 'one-hour expiry');
    reset_check(analyticspro_find_password_reset_token($resetPdo, $token) !== null, 'emailed token valid');
}
$mailCount = count($resetEmails);
foreach (['missing@example.test', 'pending@example.test', 'disabled@example.test', 'not-an-email'] as $email) {
    reset_check(analyticspro_request_password_reset($email) === ANALYTICSPRO_PASSWORD_RESET_MESSAGE, 'unknown/ineligible/invalid email gets identical response');
}
reset_check(count($resetEmails) === $mailCount, 'no email for ineligible accounts');

$old = analyticspro_create_password_reset_token($resetPdo, 1);
$token = analyticspro_create_password_reset_token($resetPdo, 1);
reset_check($old !== $token && analyticspro_find_password_reset_token($resetPdo, $old) === null, 'new request invalidates previous token');
reset_check((int) $resetPdo->query('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = 1')->fetchColumn() === 1, 'tokens do not accumulate');
foreach ([4, 5, 999] as $id) {
    reset_check(analyticspro_create_password_reset_token($resetPdo, $id) === null, 'ineligible account cannot generate token');
}
foreach (['', 'invalid', str_repeat('a', 63), str_repeat('a', 65), strtoupper($token), str_repeat('a', 64)] as $invalid) {
    reset_check(analyticspro_find_password_reset_token($resetPdo, $invalid) === null, 'malformed or unknown token rejected');
}
foreach (["expires_at = '2000-01-01'", 'used_at = CURRENT_TIMESTAMP'] as $mutation) {
    $resetPdo = new PasswordResetTestPdo();
    $token = analyticspro_create_password_reset_token($resetPdo, 1);
    $resetPdo->exec("UPDATE password_reset_tokens SET {$mutation}");
    reset_check(analyticspro_find_password_reset_token($resetPdo, $token) === null, 'expired/used token hidden');
    $before = reset_snapshot($resetPdo);
    reset_reject(fn () => analyticspro_reset_password($resetPdo, $token, 'NewPassword123', 'NewPassword123'), 'expired/used reset rejected');
    reset_check(reset_snapshot($resetPdo) === $before && $resetPdo->rolledBack, 'invalid token causes no changes');
}

foreach ([['short', 'short'], ['NewPassword123', 'different']] as [$password, $confirm]) {
    $resetPdo = new PasswordResetTestPdo();
    $token = analyticspro_create_password_reset_token($resetPdo, 1);
    $before = reset_snapshot($resetPdo);
    reset_reject(fn () => analyticspro_reset_password($resetPdo, $token, $password, $confirm), 'password validation');
    reset_check(reset_snapshot($resetPdo) === $before, 'validation preserves token');
}

foreach ([1, 2, 3] as $id) {
    $resetPdo = new PasswordResetTestPdo();
    $token = analyticspro_create_password_reset_token($resetPdo, $id);
    $verifiedUser = $resetPdo->query('SELECT * FROM users WHERE id = ' . $id)->fetch();
    $otherBefore = $resetPdo->query('SELECT * FROM users WHERE id <> ' . $id)->fetchAll();
    $resetPdo->exec("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES ({$id}, 'another-hash', '2099-01-01')");
    analyticspro_reset_password($resetPdo, $token, 'NewPassword123', 'NewPassword123');
    $user = $resetPdo->query('SELECT * FROM users WHERE id = ' . $id)->fetch();
    reset_check(password_verify('NewPassword123', $user['password_hash']) && (int) $user['must_change_password'] === 0, 'valid token changes password for every role');
    reset_check($user['remember_token'] === null && $user['remember_token_expires_at'] === null, 'remember tokens revoked');
    reset_check((int) $resetPdo->query('SELECT COUNT(*) FROM user_sessions WHERE user_id = ' . $id)->fetchColumn() === 0, 'all user sessions revoked');
    reset_check((int) $resetPdo->query('SELECT COUNT(*) FROM password_reset_tokens WHERE used_at IS NULL AND user_id = ' . $id)->fetchColumn() === 0, 'all pending reset tokens consumed');
    reset_check($otherBefore === $resetPdo->query('SELECT * FROM users WHERE id <> ' . $id)->fetchAll(), 'other users unchanged');
    $afterReset = reset_snapshot($resetPdo);
    reset_reject(fn () => analyticspro_login($verifiedUser), 'stale password/remember authentication cannot create a session after reset');
    reset_check(reset_snapshot($resetPdo) === $afterReset, 'stale login rolls back without restoring access');
    reset_reject(fn () => analyticspro_reset_password($resetPdo, $token, 'NewPassword456', 'NewPassword456'), 'double use rejected');
    reset_check(count(array_filter($resetPdo->queries, fn ($sql) => str_contains($sql, 'FOR UPDATE'))) >= 3, 'generation and consumption lock user and token');
    $_SESSION['analyticspro_user'] = ['id' => $id];
    reset_check(analyticspro_current_user() === null, 'revoked PHP session no longer authenticates');
}

foreach (['UPDATE users', 'UPDATE password_reset_tokens', 'DELETE FROM user_sessions', 'COMMIT'] as $failure) {
    $resetPdo = new PasswordResetTestPdo();
    $token = analyticspro_create_password_reset_token($resetPdo, 1);
    $before = reset_snapshot($resetPdo);
    $resetPdo->failOn = $failure;
    reset_reject(fn () => analyticspro_reset_password($resetPdo, $token, 'NewPassword123', 'NewPassword123'), 'DB error propagated');
    reset_check($resetPdo->rolledBack && !$resetPdo->inTransaction() && reset_snapshot($resetPdo) === $before, 'reset rollback restores password, token and sessions');
}
foreach (['DELETE FROM password_reset_tokens', 'INSERT INTO password_reset_tokens', 'COMMIT'] as $failure) {
    $resetPdo = new PasswordResetTestPdo();
    analyticspro_create_password_reset_token($resetPdo, 1);
    $before = reset_snapshot($resetPdo);
    $resetPdo->failOn = $failure;
    reset_reject(fn () => analyticspro_create_password_reset_token($resetPdo, 1), 'generation DB error propagated');
    reset_check($resetPdo->rolledBack && reset_snapshot($resetPdo) === $before, 'generation rollback preserves previous token');
}
foreach (['used_at = CURRENT_TIMESTAMP', "expires_at = '2000-01-01'", "token_hash = 'replacement'"] as $mutation) {
    $resetPdo = new PasswordResetTestPdo();
    $token = analyticspro_create_password_reset_token($resetPdo, 1);
    $resetPdo->onUserLock = fn (PDO $pdo) => $pdo->exec("UPDATE password_reset_tokens SET {$mutation}");
    reset_reject(fn () => analyticspro_reset_password($resetPdo, $token, 'NewPassword123', 'NewPassword123'), 'token rechecked after acquiring lock');
    reset_check($resetPdo->query('SELECT password_hash FROM users WHERE id = 1')->fetchColumn() === 'old', 'race cannot change password');
}
$resetPdo = new PasswordResetTestPdo();
$token = analyticspro_create_password_reset_token($resetPdo, 1);
$resetPdo->exec("UPDATE users SET status = 'disabled' WHERE id = 1");
reset_reject(fn () => analyticspro_reset_password($resetPdo, $token, 'NewPassword123', 'NewPassword123'), 'disabled after issuance rejected');

$resetPdo = new PasswordResetTestPdo();
$resetPdo->failOn = 'INSERT INTO password_reset_tokens';
reset_check(analyticspro_request_password_reset('user@example.test') === ANALYTICSPRO_PASSWORD_RESET_MESSAGE, 'DB failure does not enumerate');
$resetPdo->failOn = null;
$resetMailResult = false;
reset_check(analyticspro_request_password_reset('user@example.test') === ANALYTICSPRO_PASSWORD_RESET_MESSAGE, 'SMTP failure does not enumerate');
$resetMailThrows = true;
reset_check(analyticspro_request_password_reset('user@example.test') === ANALYTICSPRO_PASSWORD_RESET_MESSAGE, 'SMTP exception does not enumerate');
$resetMailThrows = false;
$resetAppUrl = '/analyticspro';
$before = reset_snapshot($resetPdo);
reset_check(analyticspro_request_password_reset('user@example.test') === ANALYTICSPRO_PASSWORD_RESET_MESSAGE && reset_snapshot($resetPdo) === $before, 'relative APP_URL does not generate unusable secret link');

$resetPdo->beginTransaction();
reset_reject(fn () => analyticspro_create_password_reset_token($resetPdo, 1), 'nested generation rejected');
reset_reject(fn () => analyticspro_reset_password($resetPdo, $token, 'NewPassword123', 'NewPassword123'), 'nested consumption rejected');
reset_check($resetPdo->inTransaction(), 'caller transaction preserved');
$resetPdo->rollBack();

foreach (['forgot_password.php', 'reset_password.php'] as $page) {
    $source = file_get_contents(dirname(__DIR__) . '/' . $page);
    reset_check(str_contains($source, 'analyticspro_require_guest()'), 'public pages guest-only');
    reset_check(str_contains($source, 'analyticspro_verify_csrf(') && str_contains($source, 'name="csrf_token"'), 'both forms protect CSRF');
}
$source = file_get_contents(dirname(__DIR__) . '/reset_password.php');
reset_check(str_contains($source, "analyticspro_redirect('login.php')") && !str_contains($source, 'analyticspro_login('), 'successful reset goes to login without auto-login');
reset_check(str_contains($source, 'Referrer-Policy: no-referrer') && str_contains($source, 'Cache-Control: no-store'), 'secret page disables referrer and caching');

define('ANALYTICSPRO_BOOTSTRAPPED', true);
define('ANALYTICSPRO_ROOT', dirname(__DIR__));
require_once dirname(__DIR__) . '/includes/csrf.php';
$resetAppUrl = 'https://example.test/analyticspro';
$resetPdo = new PasswordResetTestPdo();
$_SESSION = [];
$pageToken = analyticspro_create_password_reset_token($resetPdo, 1);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/forgot_password.php';
ob_start();
require dirname(__DIR__) . '/forgot_password.php';
$html = ob_get_clean();
reset_check(str_contains($html, 'name="email"') && str_contains($html, 'name="csrf_token"'), 'forgot page renders email/CSRF form');
reset_check(!str_contains($html, 'name="password"'), 'forgot form only asks for email');
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/reset_password.php';
$_GET = ['token' => $pageToken];
ob_start();
require dirname(__DIR__) . '/reset_password.php';
$html = ob_get_clean();
reset_check(str_contains($html, 'name="password_confirm"') && str_contains($html, 'value="' . $pageToken . '"'), 'valid link renders reset form');
$_GET = ['token' => 'invalid'];
ob_start();
require dirname(__DIR__) . '/reset_password.php';
$html = ob_get_clean();
reset_check(str_contains($html, ANALYTICSPRO_PASSWORD_RESET_INVALID) && !str_contains($html, 'name="password"'), 'invalid link renders error instead of form');
reset_check(str_contains($html, 'forgot_password.php'), 'invalid link offers new request');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['token' => $pageToken, 'csrf_token' => analyticspro_csrf_token(), 'password' => 'NewPassword123', 'password_confirm' => 'mismatch'];
ob_start();
require dirname(__DIR__) . '/reset_password.php';
$html = ob_get_clean();
reset_check(str_contains($html, 'Le password non coincidono.'), 'page renders validation error');
$_POST['password_confirm'] = $_POST['password'];
$_POST['csrf_token'] = 'invalid-csrf';
ob_start();
require dirname(__DIR__) . '/reset_password.php';
$html = ob_get_clean();
reset_check(str_contains($html, 'Richiesta non riuscita. Riprova.'), 'page rejects invalid CSRF');
reset_check($resetPdo->query('SELECT password_hash FROM users WHERE id = 1')->fetchColumn() === 'old', 'invalid page submissions do not change password');
echo "OK: {$checks} password reset checks passed.\n";
