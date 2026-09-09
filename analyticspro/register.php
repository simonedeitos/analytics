<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

analyticspro_require_guest();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        analyticspro_verify_csrf(analyticspro_post('csrf_token'));
        $nome = trim((string) analyticspro_post('nome'));
        $cognome = trim((string) analyticspro_post('cognome'));
        $email = mb_strtolower(trim((string) analyticspro_post('email')), 'UTF-8');
        $password = (string) analyticspro_post('password');

        if ($nome === '' || $cognome === '' || $email === '' || $password === '') {
            throw new RuntimeException('Compila tutti i campi obbligatori.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email non valida.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('La password deve contenere almeno 8 caratteri.');
        }
        if (analyticspro_find_user_by_email($email)) {
            throw new RuntimeException('Esiste già un account con questa email.');
        }

        $pdo = analyticspro_db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO users (role, nome, cognome, email, password_hash, must_change_password, status, can_view_phone) VALUES ('user', :nome, :cognome, :email, :password_hash, 0, 'pending', 0)");
        $stmt->execute([
            'nome' => $nome,
            'cognome' => $cognome,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        ]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO registration_requests (user_id, email_sent_to_admin) VALUES (:user_id, 0)')->execute(['user_id' => $userId]);
        $pdo->commit();

        $adminEmail = analyticspro_system_config('admin_notification_email');
        $emailSent = false;
        if ($adminEmail) {
            $emailSent = analyticspro_send_email($adminEmail, 'Nuova registrazione AnalyticsPRO', sprintf('<p>Nuova registrazione: <strong>%s %s</strong> (%s)</p>', analyticspro_h($nome), analyticspro_h($cognome), analyticspro_h($email)));
        }
        analyticspro_db()->prepare('UPDATE registration_requests SET email_sent_to_admin = :flag WHERE user_id = :user_id')->execute([
            'flag' => $emailSent ? 1 : 0,
            'user_id' => $userId,
        ]);

        analyticspro_set_flash('success', 'Registrazione inviata. Attendi l\'approvazione dell\'admin prima del login.');
        analyticspro_redirect('login.php');
    } catch (Throwable $exception) {
        analyticspro_set_flash('danger', $exception->getMessage());
        analyticspro_redirect('register.php');
    }
}

analyticspro_render_header('Registrazione', ['body_class' => 'bg-auth', 'auth_page' => true]);
?>
<div class="card border-0 shadow-lg ap-auth-card">
    <div class="ap-auth-grid">
        <div class="ap-auth-brand">
            <div>
                <div class="ap-page-eyebrow text-white-50">Nuovo tenant</div>
                <h1>Richiedi l'accesso ad AnalyticsPRO</h1>
                <p>Crea il tuo account principale per gestire immobili geolocalizzati, team e workflow di import in un'unica applicazione.</p>
            </div>
            <ul class="mb-0 ps-3 small">
                <li>Attivazione tramite approvazione admin.</li>
                <li>Tenant isolato dai dati degli altri utenti.</li>
                <li>Subutenti invitabili dopo l'attivazione.</li>
            </ul>
        </div>
        <div class="ap-auth-panel">
            <div class="ap-auth-panel-inner">
                <h2 class="h3 mb-2">Registrazione utente principale</h2>
                <p class="text-muted small mb-4">Compila i campi richiesti per inviare la richiesta di attivazione.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nome</label>
                            <input type="text" name="nome" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Cognome</label>
                            <input type="text" name="cognome" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" minlength="8" required>
                        </div>
                    </div>
                    <button class="btn btn-primary w-100 mt-4" type="submit">Invia registrazione</button>
                </form>
                <p class="text-center small mt-4 mb-0">Hai già un account? <a href="<?= analyticspro_h(analyticspro_base_url('login.php')) ?>">Accedi</a></p>
            </div>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(); ?>
