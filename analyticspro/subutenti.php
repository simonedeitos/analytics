<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

analyticspro_require_auth();
$user = analyticspro_current_user();
if (($user['role'] ?? '') === 'subuser' && !empty($user['must_change_password'])) {
    analyticspro_redirect('change_password.php');
}
if (!analyticspro_is_main_user()) {
    analyticspro_set_flash('danger', 'Accesso non consentito.');
    analyticspro_redirect('dashboard.php');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        analyticspro_verify_csrf(analyticspro_post('csrf_token'));
        $action = (string) analyticspro_post('action');
        $pdo    = analyticspro_db();

        if ($action === 'invite_subuser') {
            $nome     = trim((string) analyticspro_post('nome'));
            $cognome  = trim((string) analyticspro_post('cognome'));
            $email    = mb_strtolower(trim((string) analyticspro_post('email')), 'UTF-8');
            if ($nome === '' || $cognome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Dati subutente non validi.');
            }
            if (analyticspro_find_user_by_email($email)) {
                throw new RuntimeException('Esiste già un account con questa email.');
            }

            $temporaryPassword = analyticspro_random_password();
            $token     = bin2hex(random_bytes(24));
            $expiresAt = (new DateTimeImmutable('+48 hours'))->format('Y-m-d H:i:s');

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO users (parent_user_id, role, nome, cognome, email, password_hash, must_change_password, status, can_view_phone) VALUES (:parent_user_id, 'subuser', :nome, :cognome, :email, :password_hash, 1, 'active', 0)")
                ->execute([
                    'parent_user_id' => $user['id'],
                    'nome'           => $nome,
                    'cognome'        => $cognome,
                    'email'          => $email,
                    'password_hash'  => password_hash($temporaryPassword, PASSWORD_BCRYPT),
                ]);
            $subuserId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO subuser_permissions (subuser_id, can_edit_all_markers, can_import, can_view_analytics, can_view_reports, can_export) VALUES (:subuser_id, :can_edit_all_markers, :can_import, :can_view_analytics, :can_view_reports, :can_export)')
                ->execute([
                    'subuser_id'           => $subuserId,
                    'can_edit_all_markers' => analyticspro_post('can_edit_all_markers') === '1' ? 1 : 0,
                    'can_import'           => analyticspro_post('can_import') === '1' ? 1 : 0,
                    'can_view_analytics'   => analyticspro_post('can_view_analytics') === '1' ? 1 : 0,
                    'can_view_reports'     => analyticspro_post('can_view_reports') === '1' ? 1 : 0,
                    'can_export'           => analyticspro_post('can_export') === '1' ? 1 : 0,
                ]);
            $pdo->prepare('INSERT INTO subuser_invitations (subuser_id, invited_by, token, expires_at) VALUES (:subuser_id, :invited_by, :token, :expires_at)')
                ->execute([
                    'subuser_id' => $subuserId,
                    'invited_by' => $user['id'],
                    'token'      => $token,
                    'expires_at' => $expiresAt,
                ]);
            $pdo->commit();

            $inviteUrl = analyticspro_base_url('login.php?email=' . rawurlencode($email) . '&invitation=' . rawurlencode($token));
            analyticspro_send_email($email, 'Invito AnalyticsPRO', sprintf(
                '<p>Sei stato invitato su AnalyticsPRO.</p><p><strong>Password temporanea:</strong> %s</p><p><a href="%s">Apri la pagina di login</a></p>',
                analyticspro_h($temporaryPassword),
                analyticspro_h($inviteUrl)
            ));
            analyticspro_set_flash('success', 'Subutente creato e invitato. Password temporanea: ' . $temporaryPassword);
            analyticspro_redirect('subutenti.php');
        }

        if ($action === 'update_subuser_permissions') {
            $subuserId  = (int) analyticspro_post('subuser_id');
            $ownerCheck = $pdo->prepare("SELECT id FROM users WHERE id = :id AND parent_user_id = :parent_user_id AND role = 'subuser' LIMIT 1");
            $ownerCheck->execute(['id' => $subuserId, 'parent_user_id' => $user['id']]);
            if (!$ownerCheck->fetch()) {
                throw new RuntimeException('Subutente non valido.');
            }
            $pdo->prepare('UPDATE subuser_permissions SET can_edit_all_markers = :can_edit_all_markers, can_import = :can_import, can_view_analytics = :can_view_analytics, can_view_reports = :can_view_reports, can_export = :can_export WHERE subuser_id = :subuser_id')
                ->execute([
                    'subuser_id'           => $subuserId,
                    'can_edit_all_markers' => analyticspro_post('can_edit_all_markers') === '1' ? 1 : 0,
                    'can_import'           => analyticspro_post('can_import') === '1' ? 1 : 0,
                    'can_view_analytics'   => analyticspro_post('can_view_analytics') === '1' ? 1 : 0,
                    'can_view_reports'     => analyticspro_post('can_view_reports') === '1' ? 1 : 0,
                    'can_export'           => analyticspro_post('can_export') === '1' ? 1 : 0,
                ]);
            analyticspro_set_flash('success', 'Permessi subutente aggiornati.');
            analyticspro_redirect('subutenti.php');
        }
    }
} catch (Throwable $exception) {
    analyticspro_set_flash('danger', $exception->getMessage());
    analyticspro_redirect('subutenti.php');
}

$subusers = analyticspro_fetch_subusers((int) $user['id']);

analyticspro_render_header('Subutenti');
?>
<h1 class="h3 mb-4">Gestione subutenti</h1>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h5">Invita subutente</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                    <input type="hidden" name="action" value="invite_subuser">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Nome</label><input class="form-control" name="nome" required></div>
                        <div class="col-md-6"><label class="form-label">Cognome</label><input class="form-control" name="cognome" required></div>
                        <div class="col-12"><label class="form-label">Email</label><input type="email" class="form-control" name="email" required></div>
                    </div>
                    <div class="row g-2 mt-2 small">
                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="can_edit_all_markers" value="1" id="perm-edit"><label class="form-check-label" for="perm-edit">Può modificare tutti i marker</label></div></div>
                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="can_import" value="1" id="perm-import"><label class="form-check-label" for="perm-import">Può importare</label></div></div>
                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="can_view_analytics" value="1" id="perm-analytics"><label class="form-check-label" for="perm-analytics">Può vedere analitiche</label></div></div>
                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="can_view_reports" value="1" id="perm-reports"><label class="form-check-label" for="perm-reports">Può vedere report</label></div></div>
                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="can_export" value="1" checked id="perm-export"><label class="form-check-label" for="perm-export">Può esportare</label></div></div>
                    </div>
                    <button class="btn btn-primary mt-3" type="submit">INVITA</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h5">Permessi subutenti</h2>
                <?php if (!$subusers): ?>
                    <p class="text-muted mb-0">Nessun subutente creato.</p>
                <?php endif; ?>
                <?php foreach ($subusers as $subuser): ?>
                    <form method="post" class="border rounded p-3 mb-3 bg-light-subtle">
                        <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_subuser_permissions">
                        <input type="hidden" name="subuser_id" value="<?= analyticspro_h((string) $subuser['id']) ?>">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <strong><?= analyticspro_h(analyticspro_full_name($subuser)) ?></strong>
                            <span class="text-muted small"><?= analyticspro_h((string) $subuser['email']) ?></span>
                        </div>
                        <div class="row g-2 small">
                            <?php foreach (['can_edit_all_markers' => 'Modifica tutti i marker', 'can_import' => 'Importa dati', 'can_view_analytics' => 'Analitiche', 'can_view_reports' => 'Report', 'can_export' => 'Export'] as $key => $label): ?>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="<?= analyticspro_h($key) ?>" value="1" <?= !empty($subuser[$key]) ? 'checked' : '' ?>>
                                        <label class="form-check-label"><?= analyticspro_h($label) ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="btn btn-outline-primary btn-sm mt-3" type="submit">Salva permessi</button>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(); ?>
