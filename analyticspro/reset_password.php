<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/password_reset_service.php';

analyticspro_require_guest();
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$tokenValue = $_SERVER['REQUEST_METHOD'] === 'POST' ? analyticspro_post('token', '') : analyticspro_get('token', '');
$token = is_string($tokenValue) ? $tokenValue : '';
$error = null;
$valid = false;
try {
    $valid = analyticspro_find_password_reset_token(analyticspro_db(), $token) !== null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        analyticspro_verify_csrf(analyticspro_post('csrf_token'));
        if (!$valid) {
            throw new RuntimeException(ANALYTICSPRO_PASSWORD_RESET_INVALID);
        }
        analyticspro_reset_password(analyticspro_db(), $token, (string) analyticspro_post('password'), (string) analyticspro_post('password_confirm'));
        analyticspro_set_flash('success', 'Password aggiornata correttamente. Accedi con la nuova password.');
        analyticspro_redirect('login.php');
    }
} catch (RuntimeException $exception) {
    $knownErrors = [
        ANALYTICSPRO_PASSWORD_RESET_INVALID,
        'La nuova password deve contenere almeno 8 caratteri.',
        'Le password non coincidono.',
    ];
    $error = in_array($exception->getMessage(), $knownErrors, true) ? $exception->getMessage() : 'Richiesta non riuscita. Riprova.';
    if ($error === ANALYTICSPRO_PASSWORD_RESET_INVALID) {
        $valid = false;
    }
} catch (Throwable $exception) {
    $error = 'Richiesta non riuscita. Riprova.';
}

analyticspro_render_header('Nuova password', ['body_class' => 'bg-auth', 'auth_page' => true]);
?>
<div class="card border-0 shadow-lg ap-auth-card">
    <div class="ap-auth-grid">
        <div class="ap-auth-brand ap-auth-brand-image">
            <div>
                <div class="ap-page-eyebrow text-white-50">Sicurezza account</div>
                <h1>Imposta una nuova password</h1>
            </div>
        </div>
        <div class="ap-auth-panel">
            <div class="ap-auth-panel-inner">
                <h2 class="h3 mb-2">Nuova password</h2>
                <?php if ($error !== null): ?>
                    <div class="alert alert-danger" role="alert"><?= analyticspro_h($error) ?></div>
                <?php endif; ?>
                <?php if (!$valid): ?>
                    <?php if ($error === null): ?>
                        <div class="alert alert-danger" role="alert"><?= analyticspro_h(ANALYTICSPRO_PASSWORD_RESET_INVALID) ?></div>
                    <?php endif; ?>
                    <a href="<?= analyticspro_h(analyticspro_base_url('forgot_password.php')) ?>">Richiedi un nuovo link di reset</a>
                <?php else: ?>
                    <form method="post" action="<?= analyticspro_h(analyticspro_base_url('reset_password.php')) ?>">
                        <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                        <input type="hidden" name="token" value="<?= analyticspro_h($token) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="password">Nuova password</label>
                            <input type="password" id="password" name="password" class="form-control" autocomplete="new-password" minlength="8" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="password_confirm">Conferma password</label>
                            <input type="password" id="password_confirm" name="password_confirm" class="form-control" autocomplete="new-password" minlength="8" required>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Salva password</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(); ?>
