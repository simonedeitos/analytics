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
        $targetUserId = (int) analyticspro_post('user_id');
        $decision     = (string) analyticspro_post('decision');

        if ($decision === 'approve') {
            $pdo->prepare("UPDATE users SET status = 'active', approved_at = NOW(), approved_by = :approved_by WHERE id = :id AND status = 'pending'")
                ->execute(['approved_by' => $user['id'], 'id' => $targetUserId]);
            analyticspro_set_flash('success', 'Registrazione approvata.');
        } elseif ($decision === 'reject') {
            $pdo->prepare("UPDATE users SET status = 'disabled', approved_by = :approved_by, approved_at = NOW() WHERE id = :id AND status = 'pending'")
                ->execute(['approved_by' => $user['id'], 'id' => $targetUserId]);
            analyticspro_set_flash('warning', 'Registrazione rifiutata.');
        }
        analyticspro_redirect('admin/registrazioni.php');
    }
} catch (Throwable $exception) {
    analyticspro_set_flash('danger', $exception->getMessage());
    analyticspro_redirect('admin/registrazioni.php');
}

$pendingRegistrations = analyticspro_db()
    ->query("SELECT r.id AS request_id, u.* FROM registration_requests r JOIN users u ON u.id = r.user_id WHERE u.status = 'pending' ORDER BY r.created_at ASC")
    ->fetchAll();

analyticspro_render_header('Registrazioni pendenti');
require __DIR__ . '/_admin_subnav.php';
?>
<h1 class="h3 mb-4">Registrazioni in attesa</h1>

<?php if (!$pendingRegistrations): ?>
    <div class="alert alert-info">Nessuna registrazione in attesa.</div>
<?php endif; ?>

<?php foreach ($pendingRegistrations as $pending): ?>
    <form method="post" class="card border-0 shadow-sm mb-3">
        <div class="card-body d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
            <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
            <input type="hidden" name="user_id" value="<?= analyticspro_h((string) $pending['id']) ?>">
            <div>
                <strong><?= analyticspro_h(analyticspro_full_name($pending)) ?></strong>
                <div class="text-muted small"><?= analyticspro_h((string) $pending['email']) ?></div>
                <div class="text-muted small">Registrato il <?= analyticspro_h((string) $pending['created_at']) ?></div>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-success btn-sm" type="submit" name="decision" value="approve">
                    <i class="bi bi-check-circle me-1"></i>Approva
                </button>
                <button class="btn btn-outline-danger btn-sm" type="submit" name="decision" value="reject">
                    <i class="bi bi-x-circle me-1"></i>Rifiuta
                </button>
            </div>
        </div>
    </form>
<?php endforeach; ?>
<?php analyticspro_render_footer(); ?>
