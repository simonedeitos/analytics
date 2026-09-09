<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ui/helpers.php';

analyticspro_require_auth();
$user = analyticspro_current_user();

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
<div class="card border-0 shadow-lg ap-auth-card">
    <div class="ap-auth-grid">
        <div class="ap-auth-brand">
            <div>
                <div class="ap-page-eyebrow text-white-50">Sicurezza account</div>
                <h1>Cambia password</h1>
                <p class="mb-0">Mantieni protetto l'accesso al tuo tenant aggiornando la password con una chiave personale forte e sicura.</p>
            </div>
            <ul class="mb-0 ps-3 small">
                <li>Minimo 8 caratteri.</li>
                <li>Operazione valida per utenti principali, subutenti e admin.</li>
                <li>Dopo il salvataggio tornerai alla dashboard.</li>
            </ul>
        </div>
        <div class="ap-auth-panel">
            <div class="ap-auth-panel-inner">
                <h2 class="h3 mb-2">Nuova password</h2>
                <p class="text-muted small mb-4">Imposta una nuova password per continuare a usare AnalyticsPRO in sicurezza.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                    <div class="mb-3">
                        <label class="form-label">Nuova password</label>
                        <input type="password" name="password" class="form-control" minlength="8" required>
                    </div>
                    <div class="mb-4">
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
