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
        analyticspro_redirect('login.php');
    }
}

analyticspro_render_header('Login', ['body_class' => 'bg-auth']);
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card shadow-sm border-0 auth-card mt-4">
            <div class="card-body p-4">
                <h1 class="h3 mb-3 text-center">Accedi ad AnalyticsPRO</h1>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= analyticspro_h((string) analyticspro_get('email', '')) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" value="1" id="remember" name="remember">
                        <label class="form-check-label" for="remember">Ricordami per 10 ore</label>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Login</button>
                </form>
                <p class="text-center small mt-3 mb-0">Non hai un account? <a href="<?= analyticspro_h(analyticspro_base_url('register.php')) ?>">Registrati</a></p>
            </div>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(); ?>
