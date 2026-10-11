<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ui/helpers.php';
require_once __DIR__ . '/includes/team_service.php';

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
            analyticspro_save_subuser_permissions($pdo, $subuserId, $_POST);
            $pdo->prepare('INSERT INTO subuser_invitations (subuser_id, invited_by, token, expires_at) VALUES (:subuser_id, :invited_by, :token, :expires_at)')
                ->execute([
                    'subuser_id' => $subuserId,
                    'invited_by' => $user['id'],
                    'token'      => $token,
                    'expires_at' => $expiresAt,
                ]);
            $pdo->commit();

            $inviteUrl = analyticspro_base_url('login.php?email=' . rawurlencode($email) . '&invitation=' . rawurlencode($token));
            analyticspro_send_email($email, 'EasyCatasto: Invito EasyRadar', sprintf(
                '<p>Sei stato invitato come subutente su EasyRadar.</p><p><strong>Password temporanea:</strong> %s</p><p><a href="%s">Apri la pagina di login</a></p>',
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
            analyticspro_save_subuser_permissions($pdo, $subuserId, $_POST);
            analyticspro_set_flash('success', 'Permessi subutente aggiornati.');
            analyticspro_redirect('subutenti.php');
        }
        if ($action === 'disable_subuser') {
            analyticspro_disable_subuser(
                $pdo,
                (int) $user['id'],
                (int) analyticspro_post('subuser_id'),
                (string) analyticspro_post('assignment_mode'),
                analyticspro_post('recipient_id') ? (int) analyticspro_post('recipient_id') : null
            );
            analyticspro_set_flash('success', 'Collaboratore disabilitato e assegnazioni aggiornate.');
            analyticspro_redirect('subutenti.php');
        }
        if ($action === 'reactivate_subuser') {
            analyticspro_reactivate_subuser($pdo, (int) $user['id'], (int) analyticspro_post('subuser_id'));
            analyticspro_set_flash('success', 'Collaboratore riabilitato. Dovrà effettuare nuovamente l’accesso.');
            analyticspro_redirect('subutenti.php');
        }
    }
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    analyticspro_set_flash('danger', $exception->getMessage());
    analyticspro_redirect('subutenti.php');
}

$subusers = analyticspro_fetch_subusers((int) $user['id']);
$activeSubusers = array_values(array_filter($subusers, static fn (array $subuser): bool => ($subuser['status'] ?? '') === 'active'));
$assignmentStmt = analyticspro_db()->prepare('SELECT pa.subuser_id, COUNT(*) AS assignment_count FROM property_assignments pa INNER JOIN properties p ON p.id = pa.property_id WHERE p.user_id = :owner_id GROUP BY pa.subuser_id');
$assignmentStmt->execute(['owner_id' => (int) $user['id']]);
$assignmentCounts = [];
foreach ($assignmentStmt->fetchAll() as $assignmentCount) {
    $assignmentCounts[(int) $assignmentCount['subuser_id']] = (int) $assignmentCount['assignment_count'];
}

analyticspro_render_header('Team', ['body_class' => 'team-page']);
?>
<?= analyticspro_ui_page_header('Il tuo team', 'Invita i tuoi collaboratori e gestisci i permessi di accesso al tuo spazio di lavoro.', '', ['eyebrow' => 'Gestione']) ?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 mb-2"><i class="bi bi-person-plus text-primary me-2" aria-hidden="true"></i>Invita un collaboratore</h2>
                <p class="text-muted small mb-4">Riceverà un invito via email per accedere al tuo team.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                    <input type="hidden" name="action" value="invite_subuser">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="invite-nome">Nome</label><input class="form-control" id="invite-nome" name="nome" autocomplete="given-name" required></div>
                        <div class="col-md-6"><label class="form-label" for="invite-cognome">Cognome</label><input class="form-control" id="invite-cognome" name="cognome" autocomplete="family-name" required></div>
                        <div class="col-12"><label class="form-label" for="invite-email">Email</label><input type="email" class="form-control" id="invite-email" name="email" autocomplete="email" required></div>
                    </div>
                    <div class="row g-2 mt-2 small">
                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="can_edit_all_markers" value="1" id="perm-edit"><label class="form-check-label" for="perm-edit">Può modificare tutti i marker</label></div></div>
                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="can_import" value="1" id="perm-import"><label class="form-check-label" for="perm-import">Può importare</label></div></div>
                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="can_view_analytics" value="1" id="perm-analytics"><label class="form-check-label" for="perm-analytics">Può vedere analitiche</label></div></div>
                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="can_view_reports" value="1" id="perm-reports"><label class="form-check-label" for="perm-reports">Può vedere proprietari</label></div></div>
                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="can_export" value="1" checked id="perm-export"><label class="form-check-label" for="perm-export">Può esportare</label></div></div>
                    </div>
                    <button class="btn btn-primary mt-4" type="submit"><i class="bi bi-send me-2" aria-hidden="true"></i>Invia invito</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Collaboratori e permessi <span class="badge text-bg-light ms-2"><?= count($subusers) ?></span></h2>
                <?php if (!$subusers): ?>
                    <?= analyticspro_ui_empty_state(['icon' => 'bi-people', 'title' => 'Il tuo team parte da qui', 'message' => 'Invita il primo collaboratore per assegnare marker, report e analitiche.']) ?>
                <?php endif; ?>
                <div class="row row-cols-1 row-cols-md-2 g-2">
                <?php foreach ($subusers as $subuser): ?>
                    <div class="col">
                    <div class="border rounded-4 p-2 h-100 ap-subtle-block">
                    <form method="post" id="subuser-permissions-<?= (int) $subuser['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_subuser_permissions">
                        <input type="hidden" name="subuser_id" value="<?= analyticspro_h((string) $subuser['id']) ?>">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <div class="me-auto">
                                <strong><?= analyticspro_h(analyticspro_full_name($subuser)) ?></strong>
                                <div class="text-muted small"><?= analyticspro_h((string) $subuser['email']) ?></div>
                            </div>
                            <span class="badge <?= $subuser['status'] === 'active' ? 'text-bg-success' : ($subuser['status'] === 'disabled' ? 'text-bg-secondary' : 'text-bg-warning') ?>"><?= analyticspro_h(['active' => 'Attivo', 'pending' => 'In attesa', 'disabled' => 'Disabilitato'][$subuser['status']] ?? (string) $subuser['status']) ?></span>
                        </div>
                        <div class="row row-cols-2 g-1 small">
                            <?php foreach (['can_edit_all_markers' => 'Modifica tutti i marker', 'can_import' => 'Importa dati', 'can_view_analytics' => 'Analitiche', 'can_view_reports' => 'Proprietari', 'can_export' => 'Export'] as $key => $label): ?>
                                <div class="col">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="subuser-<?= (int) $subuser['id'] ?>-<?= analyticspro_h($key) ?>" name="<?= analyticspro_h($key) ?>" value="1" <?= !empty($subuser[$key]) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="subuser-<?= (int) $subuser['id'] ?>-<?= analyticspro_h($key) ?>"><?= analyticspro_h($label) ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </form>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button class="btn btn-outline-primary btn-sm" type="submit" form="subuser-permissions-<?= (int) $subuser['id'] ?>">Salva permessi</button>
                    <?php if ($subuser['status'] === 'active'): ?>
                        <form method="post" data-team-disable-form>
                            <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                            <input type="hidden" name="action" value="disable_subuser">
                            <input type="hidden" name="subuser_id" value="<?= (int) $subuser['id'] ?>">
                            <input type="hidden" name="assignment_mode" value="keep">
                            <input type="hidden" name="recipient_id" value="">
                            <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#team-disable-modal" data-team-id="<?= (int) $subuser['id'] ?>" data-team-name="<?= analyticspro_h(analyticspro_full_name($subuser)) ?>" data-team-count="<?= $assignmentCounts[(int) $subuser['id']] ?? 0 ?>">Disabilita collaboratore</button>
                        </form>
                    <?php else: ?>
                        <?php if ($subuser['status'] === 'disabled'): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                                <input type="hidden" name="action" value="reactivate_subuser">
                                <input type="hidden" name="subuser_id" value="<?= (int) $subuser['id'] ?>">
                                <button class="btn btn-outline-success btn-sm" type="submit">Riabilita collaboratore</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                    </div>
                    </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="team-disable-modal" tabindex="-1" aria-labelledby="team-disable-title" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="team-disable-title">Disabilita collaboratore</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <p><strong id="team-disable-name"></strong> non potrà più accedere. Le sessioni attive saranno revocate.</p>
                <p>Marker assegnati: <strong id="team-disable-count">0</strong></p>
                <fieldset>
                    <legend class="fs-6">Come gestire le assegnazioni?</legend>
                    <?php foreach (['keep' => 'Mantieni le assegnazioni', 'reassign' => 'Riassegna a un altro collaboratore', 'unassign' => 'Rimuovi le assegnazioni'] as $mode => $label): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="team_assignment_mode" id="team-mode-<?= $mode ?>" value="<?= $mode ?>" <?= $mode === 'keep' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="team-mode-<?= $mode ?>"><?= analyticspro_h($label) ?></label>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
                <p class="text-muted small mt-2" id="team-recipient-empty" hidden>Non ci sono altri collaboratori attivi a cui riassegnare i marker.</p>
                <div id="team-recipient-field" hidden>
                    <label class="form-label" for="team-recipient">Collaboratore destinatario</label>
                    <select class="form-select" id="team-recipient" disabled>
                        <option value="">Seleziona un collaboratore</option>
                        <?php foreach ($activeSubusers as $recipient): ?>
                            <option value="<?= (int) $recipient['id'] ?>"><?= analyticspro_h(analyticspro_full_name($recipient)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-danger" id="team-disable-confirm" disabled>Conferma disabilitazione</button>
            </div>
        </div>
    </div>
</div>
<script defer src="<?= analyticspro_h(analyticspro_asset_url('assets/js/team.js')) ?>"></script>
<?php analyticspro_render_footer(); ?>
