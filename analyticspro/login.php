<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

analyticspro_require_guest();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        analyticspro_verify_csrf(analyticspro_post('csrf_token'));
        $email = mb_strtolower(trim((string) analyticspro_post('email')), 'UTF-8');
        $password = (string) analyticspro_post('password');
        $remember = analyticspro_post('remember') === '1';
        $user = analyticspro_find_user_by_email($email);

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            throw new RuntimeException('Credenziali non valide.');
        }
        if (($user['status'] ?? '') === 'pending') {
            throw new RuntimeException('Account in attesa di approvazione admin.');
        }
        if (($user['status'] ?? '') !== 'active') {
            throw new RuntimeException('Account disabilitato.');
        }

        analyticspro_login($user, $remember);
        analyticspro_redirect('dashboard.php');
    } catch (Throwable $exception) {
        analyticspro_set_flash('danger', $exception->getMessage());
        analyticspro_redirect('login.php?email=' . rawurlencode((string) analyticspro_post('email', '')));
    }
}

$emailValue = (string) analyticspro_get('email', '');
analyticspro_render_header('Login', ['body_class' => 'bg-auth', 'auth_page' => true]);
?>
<div class="card border-0 shadow-lg ap-auth-card">
    <div class="ap-auth-grid">
        <div class="ap-auth-brand">
            <div>
                <div class="ap-page-eyebrow text-white-50">Bentornato</div>
                <h1>Accedi alla tua dashboard immobiliare</h1>
                <p>Controlla mappa, report, import e analitiche da una sola interfaccia moderna mantenendo il workflow AnalyticsPRO.</p>
            </div>
            <ul class="mb-0 ps-3 small">
                <li>Dashboard unificata con KPI, territorio e attività recenti.</li>
                <li>Permessi tenant e subutenti invariati.</li>
                <li>Sessione estesa fino a 10 ore con “Ricordami”.</li>
            </ul>
        </div>
        <div class="ap-auth-panel">
            <div class="ap-auth-panel-inner">
                <h2 class="h3 mb-2">Accedi ad AnalyticsPRO</h2>
                <p class="text-muted small mb-4">Inserisci le tue credenziali per entrare nel pannello.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= analyticspro_h($emailValue) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" value="1" id="remember" name="remember">
                        <label class="form-check-label" for="remember">Ricordami per 10 ore</label>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Login</button>
                </form>
                <p class="text-center small mt-4 mb-0">Non hai un account? <a href="<?= analyticspro_h(analyticspro_base_url('register.php')) ?>">Registrati</a></p>
            </div>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(); ?>
