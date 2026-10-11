<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/password_reset_service.php';

analyticspro_require_guest();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        analyticspro_verify_csrf(analyticspro_post('csrf_token'));
    } catch (Throwable $exception) {
        analyticspro_set_flash('danger', 'Richiesta non valida. Riprova.');
        analyticspro_redirect('forgot_password.php');
    }
    $message = analyticspro_request_password_reset(mb_strtolower(trim((string) analyticspro_post('email')), 'UTF-8'));
    analyticspro_set_flash('success', $message);
    analyticspro_redirect('forgot_password.php');
}

analyticspro_render_header('Password dimenticata', ['body_class' => 'bg-auth', 'auth_page' => true]);
?>
<div class="card border-0 shadow-lg ap-auth-card">
    <div class="ap-auth-grid">
        <div class="ap-auth-brand ap-auth-brand-image">
            <div>
                <div class="ap-page-eyebrow text-white-50">Recupero accesso</div>
                <h1>Hai dimenticato la password?</h1>
            </div>
        </div>
        <div class="ap-auth-panel">
            <div class="ap-auth-panel-inner">
                <h2 class="h3 mb-2">Reimposta la password</h2>
                <p class="text-muted small mb-4">Inserisci l’email del tuo account per ricevere le istruzioni.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" id="email" name="email" class="form-control" autocomplete="email" required>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Invia istruzioni</button>
                </form>
                <p class="text-center small mt-4 mb-0"><a href="<?= analyticspro_h(analyticspro_base_url('login.php')) ?>">Torna al login</a></p>
            </div>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(); ?>
