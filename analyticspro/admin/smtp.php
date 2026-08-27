<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require __DIR__ . '/_admin_check.php';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        analyticspro_verify_csrf(analyticspro_post('csrf_token'));
        $action = (string) analyticspro_post('action');

        if ($action === 'save_smtp') {
            analyticspro_save_system_config([
                'smtp_host'                 => trim((string) analyticspro_post('smtp_host')),
                'smtp_port'                 => trim((string) analyticspro_post('smtp_port')),
                'smtp_user'                 => trim((string) analyticspro_post('smtp_user')),
                'smtp_pass'                 => trim((string) analyticspro_post('smtp_pass')),
                'smtp_security'             => trim((string) analyticspro_post('smtp_security')),
                'smtp_from_email'           => trim((string) analyticspro_post('smtp_from_email')),
                'smtp_from_name'            => trim((string) analyticspro_post('smtp_from_name')),
                'admin_notification_email'  => trim((string) analyticspro_post('admin_notification_email')),
            ]);
            analyticspro_set_flash('success', 'Configurazione SMTP salvata.');
        } elseif ($action === 'test_smtp') {
            $result = analyticspro_test_smtp_connection();
            analyticspro_set_flash($result['ok'] ? 'success' : 'warning', $result['message']);
        }
        analyticspro_redirect('admin/smtp.php');
    }
} catch (Throwable $exception) {
    analyticspro_set_flash('danger', $exception->getMessage());
    analyticspro_redirect('admin/smtp.php');
}

$smtpSettings = analyticspro_smtp_settings();

analyticspro_render_header('Configurazione SMTP');
require __DIR__ . '/_admin_subnav.php';
?>
<h1 class="h3 mb-4">Configurazione SMTP</h1>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
            <input type="hidden" name="action" value="save_smtp">
            <div class="col-md-6"><label class="form-label">Host</label><input class="form-control" name="smtp_host" value="<?= analyticspro_h((string) $smtpSettings['host']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Porta</label><input class="form-control" name="smtp_port" value="<?= analyticspro_h((string) $smtpSettings['port']) ?>"></div>
            <div class="col-md-3">
                <label class="form-label">Sicurezza</label>
                <select class="form-select" name="smtp_security">
                    <option value="tls"  <?= $smtpSettings['security'] === 'tls'  ? 'selected' : '' ?>>TLS</option>
                    <option value="ssl"  <?= $smtpSettings['security'] === 'ssl'  ? 'selected' : '' ?>>SSL</option>
                    <option value="none" <?= $smtpSettings['security'] === 'none' ? 'selected' : '' ?>>Nessuna</option>
                </select>
            </div>
            <div class="col-md-6"><label class="form-label">Utente SMTP</label><input class="form-control" name="smtp_user" value="<?= analyticspro_h((string) $smtpSettings['user']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Password SMTP</label><input type="password" class="form-control" name="smtp_pass" value="<?= analyticspro_h((string) $smtpSettings['pass']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Mittente email</label><input class="form-control" name="smtp_from_email" value="<?= analyticspro_h((string) $smtpSettings['from_email']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Nome mittente</label><input class="form-control" name="smtp_from_name" value="<?= analyticspro_h((string) $smtpSettings['from_name']) ?>"></div>
            <div class="col-12"><label class="form-label">Email notifiche admin</label><input type="email" class="form-control" name="admin_notification_email" value="<?= analyticspro_h((string) analyticspro_system_config('admin_notification_email', '')) ?>"></div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit">Salva configurazione</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <h2 class="h6 mb-3">Test connessione SMTP</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
            <input type="hidden" name="action" value="test_smtp">
            <button class="btn btn-outline-secondary" type="submit">
                <i class="bi bi-send me-1"></i>Testa connessione
            </button>
        </form>
    </div>
</div>
<?php analyticspro_render_footer(); ?>
