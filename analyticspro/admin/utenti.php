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
        $action       = (string) analyticspro_post('action', 'update_user');
        $targetUserId = (int) analyticspro_post('target_user_id');
        if ($targetUserId <= 0) {
            throw new RuntimeException('Utente non valido.');
        }

        if ($action === 'purge_user_data') {
            $userStmt = $pdo->prepare("SELECT id, nome, cognome, email, role FROM users WHERE id = :id LIMIT 1");
            $userStmt->execute(['id' => $targetUserId]);
            $targetUser = $userStmt->fetch();
            if (!$targetUser || ($targetUser['role'] ?? '') !== 'user') {
                throw new RuntimeException('Puoi svuotare solo i dati di un utente principale.');
            }

            $expectedPhrase = 'SVUOTA ' . strtoupper(trim((string) ($targetUser['email'] ?? '')));
            $confirmPhrase = strtoupper(trim((string) analyticspro_post('confirm_phrase')));
            if ($confirmPhrase !== $expectedPhrase) {
                throw new RuntimeException('Conferma non valida. Digita esattamente "' . $expectedPhrase . '".');
            }

            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE user_id = :user_id');
            $countStmt->execute(['user_id' => $targetUserId]);
            $propertiesCount = (int) $countStmt->fetchColumn();

            $deleteStmt = $pdo->prepare('DELETE FROM properties WHERE user_id = :user_id');
            $deleteStmt->execute(['user_id' => $targetUserId]);
            analyticspro_set_flash('success', 'Dati utente eliminati. Immobili rimossi: ' . $propertiesCount . '.');
            analyticspro_redirect('admin/utenti.php');
        }

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
    ->query("SELECT u.id, u.parent_user_id, u.role, u.nome, u.cognome, u.email, u.status, u.can_view_phone, u.created_at, COUNT(p.id) AS property_count FROM users u LEFT JOIN properties p ON p.user_id = u.id GROUP BY u.id, u.parent_user_id, u.role, u.nome, u.cognome, u.email, u.status, u.can_view_phone, u.created_at ORDER BY u.role, u.cognome, u.nome")
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
                        <th>Immobili</th>
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
                        <td><?= (int) ($row['property_count'] ?? 0) ?></td>
                        <td>
                            <form method="post" class="d-flex gap-3 align-items-center flex-wrap">
                                <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                                <input type="hidden" name="action" value="update_user">
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
                                <?php if (($row['role'] ?? '') === 'user'): ?>
                                    <button type="button"
                                            class="btn btn-outline-danger btn-sm open-purge-user-modal"
                                            data-user-id="<?= (int) $row['id'] ?>"
                                            data-user-name="<?= analyticspro_h(analyticspro_full_name($row)) ?>"
                                            data-user-email="<?= analyticspro_h((string) $row['email']) ?>"
                                            data-property-count="<?= (int) ($row['property_count'] ?? 0) ?>">
                                        Svuota dati utente
                                    </button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="modal fade" id="purge-user-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h2 class="modal-title h5 mb-0">Svuota dati utente</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                    <input type="hidden" name="action" value="purge_user_data">
                    <input type="hidden" name="target_user_id" id="purge-target-user-id" value="">
                    <p class="mb-2">Stai per eliminare <strong>tutti i dati importati</strong> dell'utente <strong id="purge-user-name"></strong>.</p>
                    <p class="small text-muted mb-2">Verranno cancellati gli immobili e, tramite i vincoli di cascata, anche intestatari, note, assegnazioni e storico stati collegati.</p>
                    <p class="small mb-2">Immobili attuali: <strong id="purge-property-count">0</strong></p>
                    <label for="purge-confirm-phrase" class="form-label small">Digita esattamente <code id="purge-confirm-label"></code> per confermare</label>
                    <input type="text" class="form-control" name="confirm_phrase" id="purge-confirm-phrase" autocomplete="off" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-danger">Elimina definitivamente</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('purge-user-modal');
    if (!modalEl) return;
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.querySelectorAll('.open-purge-user-modal').forEach(function (button) {
        button.addEventListener('click', function () {
            var userId = button.getAttribute('data-user-id') || '';
            var userName = button.getAttribute('data-user-name') || '';
            var userEmail = button.getAttribute('data-user-email') || '';
            var propertyCount = button.getAttribute('data-property-count') || '0';
            document.getElementById('purge-target-user-id').value = userId;
            document.getElementById('purge-user-name').textContent = userName + ' (' + userEmail + ')';
            document.getElementById('purge-property-count').textContent = propertyCount;
            document.getElementById('purge-confirm-label').textContent = 'SVUOTA ' + String(userEmail).toUpperCase();
            document.getElementById('purge-confirm-phrase').value = '';
            modal.show();
        });
    });
});
</script>
<?php analyticspro_render_footer(); ?>
