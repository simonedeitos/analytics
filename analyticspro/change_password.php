<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

analyticspro_require_auth();
$user = analyticspro_current_user();
if (($user['role'] ?? '') !== 'subuser' || empty($user['must_change_password'])) {
    analyticspro_redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        analyticspro_verify_csrf(analyticspro_post('csrf_token'));
        $password = (string) analyticspro_post('password');
        $confirm = (string) analyticspro_post('password_confirm');
        if (strlen($password) < 8) {
            throw new RuntimeException('La nuova password deve contenere almeno 8 caratteri.');
        }
        if ($password !== $confirm) {
            throw new RuntimeException('Le password non coincidono.');
        }

        analyticspro_db()->prepare('UPDATE users SET password_hash = :password_hash, must_change_password = 0 WHERE id = :id')->execute([
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'id' => $user['id'],
        ]);
        $user['must_change_password'] = 0;
        analyticspro_set_current_user($user);
        analyticspro_set_flash('success', 'Password aggiornata correttamente.');
        analyticspro_redirect('dashboard.php');
    } catch (Throwable $exception) {
        analyticspro_set_flash('danger', $exception->getMessage());
        analyticspro_redirect('change_password.php');
    }
}

analyticspro_render_header('Cambio password', ['body_class' => 'bg-auth', 'auth_page' => true]);
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card shadow-sm border-0 auth-card mt-4">
            <div class="card-body p-4">
                <h1 class="h3 mb-3 text-center">Cambia password</h1>
                <p class="text-muted small">Al primo accesso il subutente deve impostare una nuova password personale.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                    <div class="mb-3">
                        <label class="form-label">Nuova password</label>
                        <input type="password" name="password" class="form-control" minlength="8" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Conferma password</label>
                        <input type="password" name="password_confirm" class="form-control" minlength="8" required>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Salva password</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(); ?>
