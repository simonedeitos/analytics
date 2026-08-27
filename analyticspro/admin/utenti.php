<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require __DIR__ . '/_admin_check.php';

$user = analyticspro_current_user();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        analyticspro_verify_csrf(analyticspro_post('csrf_token'));
        $pdo          = analyticspro_db();
        $targetUserId = (int) analyticspro_post('target_user_id');
        $status       = analyticspro_post('status') === 'active' ? 'active' : 'disabled';
        $canViewPhone = analyticspro_post('can_view_phone') === '1' ? 1 : 0;

        $pdo->prepare('UPDATE users SET status = :status, can_view_phone = :can_view_phone WHERE id = :id')
            ->execute(['status' => $status, 'can_view_phone' => $canViewPhone, 'id' => $targetUserId]);
        analyticspro_set_flash('success', 'Utente aggiornato.');
        analyticspro_redirect('admin/utenti.php');
    }
} catch (Throwable $exception) {
    analyticspro_set_flash('danger', $exception->getMessage());
    analyticspro_redirect('admin/utenti.php');
}

$usersOverview = analyticspro_db()
    ->query("SELECT id, parent_user_id, role, nome, cognome, email, status, can_view_phone, created_at FROM users ORDER BY role, cognome, nome")
    ->fetchAll();

analyticspro_render_header('Gestione utenti');
require __DIR__ . '/_admin_subnav.php';
?>
<h1 class="h3 mb-4">Gestione utenti</h1>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Email</th>
                        <th>Ruolo</th>
                        <th>Tenant</th>
                        <th>Stato</th>
                        <th>Telefono</th>
                        <th>Azione</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($usersOverview as $row): ?>
                    <tr>
                        <td><?= analyticspro_h(analyticspro_full_name($row)) ?></td>
                        <td><?= analyticspro_h((string) $row['email']) ?></td>
                        <td><span class="badge bg-secondary"><?= analyticspro_h((string) $row['role']) ?></span></td>
                        <td><?= analyticspro_h((string) ($row['parent_user_id'] ?: $row['id'])) ?></td>
                        <td>
                            <span class="badge <?= $row['status'] === 'active' ? 'bg-success' : ($row['status'] === 'pending' ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                <?= analyticspro_h((string) $row['status']) ?>
                            </span>
                        </td>
                        <td><?= !empty($row['can_view_phone']) ? '<span class="text-success">Sì</span>' : 'No' ?></td>
                        <td>
                            <form method="post" class="d-flex gap-3 align-items-center flex-wrap">
                                <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                                <input type="hidden" name="target_user_id" value="<?= analyticspro_h((string) $row['id']) ?>">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" name="status" value="active"
                                           <?= $row['status'] === 'active' ? 'checked' : '' ?> id="status-<?= (int) $row['id'] ?>">
                                    <label class="form-check-label small" for="status-<?= (int) $row['id'] ?>">Attivo</label>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" name="can_view_phone" value="1"
                                           <?= !empty($row['can_view_phone']) ? 'checked' : '' ?> id="phone-<?= (int) $row['id'] ?>">
                                    <label class="form-check-label small" for="phone-<?= (int) $row['id'] ?>">Tel</label>
                                </div>
                                <button class="btn btn-outline-primary btn-sm" type="submit">Salva</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(); ?>
