<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

analyticspro_require_auth();
$user = analyticspro_current_user();
if (($user['role'] ?? '') === 'subuser' && !empty($user['must_change_password'])) {
    analyticspro_redirect('change_password.php');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        analyticspro_verify_csrf(analyticspro_post('csrf_token'));
        $action = (string) analyticspro_post('action');
        $pdo = analyticspro_db();

        if ($action === 'registration_decision' && analyticspro_is_admin()) {
            $targetUserId = (int) analyticspro_post('user_id');
            $decision = (string) analyticspro_post('decision');
            if ($decision === 'approve') {
                $stmt = $pdo->prepare("UPDATE users SET status = 'active', approved_at = NOW(), approved_by = :approved_by WHERE id = :id AND status = 'pending'");
                $stmt->execute(['approved_by' => $user['id'], 'id' => $targetUserId]);
                analyticspro_set_flash('success', 'Registrazione approvata.');
            } elseif ($decision === 'reject') {
                $stmt = $pdo->prepare("UPDATE users SET status = 'disabled', approved_by = :approved_by, approved_at = NOW() WHERE id = :id AND status = 'pending'");
                $stmt->execute(['approved_by' => $user['id'], 'id' => $targetUserId]);
                analyticspro_set_flash('warning', 'Registrazione rifiutata.');
            }
            analyticspro_redirect('dashboard.php');
        }

        if ($action === 'save_smtp' && analyticspro_is_admin()) {
            analyticspro_save_system_config([
                'smtp_host' => trim((string) analyticspro_post('smtp_host')),
                'smtp_port' => trim((string) analyticspro_post('smtp_port')),
                'smtp_user' => trim((string) analyticspro_post('smtp_user')),
                'smtp_pass' => trim((string) analyticspro_post('smtp_pass')),
                'smtp_security' => trim((string) analyticspro_post('smtp_security')),
                'smtp_from_email' => trim((string) analyticspro_post('smtp_from_email')),
                'smtp_from_name' => trim((string) analyticspro_post('smtp_from_name')),
                'admin_notification_email' => trim((string) analyticspro_post('admin_notification_email')),
            ]);
            analyticspro_set_flash('success', 'Configurazione SMTP salvata.');
            analyticspro_redirect('dashboard.php#configurazione');
        }

        if ($action === 'test_smtp' && analyticspro_is_admin()) {
            $result = analyticspro_test_smtp_connection();
            analyticspro_set_flash($result['ok'] ? 'success' : 'warning', $result['message']);
            analyticspro_redirect('dashboard.php#configurazione');
        }

        if ($action === 'update_user' && analyticspro_is_admin()) {
            $targetUserId = (int) analyticspro_post('target_user_id');
            $status = analyticspro_post('status') === 'active' ? 'active' : 'disabled';
            $canViewPhone = analyticspro_post('can_view_phone') === '1' ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE users SET status = :status, can_view_phone = :can_view_phone WHERE id = :id');
            $stmt->execute([
                'status' => $status,
                'can_view_phone' => $canViewPhone,
                'id' => $targetUserId,
            ]);
            analyticspro_set_flash('success', 'Utente aggiornato.');
            analyticspro_redirect('dashboard.php#utenti');
        }

        if ($action === 'invite_subuser' && analyticspro_is_main_user()) {
            $nome = trim((string) analyticspro_post('nome'));
            $cognome = trim((string) analyticspro_post('cognome'));
            $email = mb_strtolower(trim((string) analyticspro_post('email')), 'UTF-8');
            if ($nome === '' || $cognome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Dati subutente non validi.');
            }
            if (analyticspro_find_user_by_email($email)) {
                throw new RuntimeException('Esiste già un account con questa email.');
            }

            $temporaryPassword = analyticspro_random_password();
            $token = bin2hex(random_bytes(24));
            $expiresAt = (new DateTimeImmutable('+48 hours'))->format('Y-m-d H:i:s');

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO users (parent_user_id, role, nome, cognome, email, password_hash, must_change_password, status, can_view_phone) VALUES (:parent_user_id, 'subuser', :nome, :cognome, :email, :password_hash, 1, 'active', 0)")
                ->execute([
                    'parent_user_id' => $user['id'],
                    'nome' => $nome,
                    'cognome' => $cognome,
                    'email' => $email,
                    'password_hash' => password_hash($temporaryPassword, PASSWORD_BCRYPT),
                ]);
            $subuserId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO subuser_permissions (subuser_id, can_edit_all_markers, can_import, can_view_analytics, can_view_reports, can_export) VALUES (:subuser_id, :can_edit_all_markers, :can_import, :can_view_analytics, :can_view_reports, :can_export)')
                ->execute([
                    'subuser_id' => $subuserId,
                    'can_edit_all_markers' => analyticspro_post('can_edit_all_markers') === '1' ? 1 : 0,
                    'can_import' => analyticspro_post('can_import') === '1' ? 1 : 0,
                    'can_view_analytics' => analyticspro_post('can_view_analytics') === '1' ? 1 : 0,
                    'can_view_reports' => analyticspro_post('can_view_reports') === '1' ? 1 : 0,
                    'can_export' => analyticspro_post('can_export') === '0' ? 0 : 1,
                ]);
            $pdo->prepare('INSERT INTO subuser_invitations (subuser_id, invited_by, token, expires_at) VALUES (:subuser_id, :invited_by, :token, :expires_at)')
                ->execute([
                    'subuser_id' => $subuserId,
                    'invited_by' => $user['id'],
                    'token' => $token,
                    'expires_at' => $expiresAt,
                ]);
            $pdo->commit();

            $inviteUrl = analyticspro_base_url('login.php?email=' . rawurlencode($email) . '&invitation=' . rawurlencode($token));
            analyticspro_send_email($email, 'Invito AnalyticsPRO', sprintf('<p>Sei stato invitato su AnalyticsPRO.</p><p><strong>Password temporanea:</strong> %s</p><p><a href="%s">Apri la pagina di login</a></p>', analyticspro_h($temporaryPassword), analyticspro_h($inviteUrl)));
            analyticspro_set_flash('success', 'Subutente creato e invitato. Password temporanea: ' . $temporaryPassword);
            analyticspro_redirect('dashboard.php#subutenti');
        }

        if ($action === 'update_subuser_permissions' && analyticspro_is_main_user()) {
            $subuserId = (int) analyticspro_post('subuser_id');
            $ownerCheck = $pdo->prepare("SELECT id FROM users WHERE id = :id AND parent_user_id = :parent_user_id AND role = 'subuser' LIMIT 1");
            $ownerCheck->execute(['id' => $subuserId, 'parent_user_id' => $user['id']]);
            if (!$ownerCheck->fetch()) {
                throw new RuntimeException('Subutente non valido.');
            }
            $pdo->prepare('UPDATE subuser_permissions SET can_edit_all_markers = :can_edit_all_markers, can_import = :can_import, can_view_analytics = :can_view_analytics, can_view_reports = :can_view_reports, can_export = :can_export WHERE subuser_id = :subuser_id')
                ->execute([
                    'subuser_id' => $subuserId,
                    'can_edit_all_markers' => analyticspro_post('can_edit_all_markers') === '1' ? 1 : 0,
                    'can_import' => analyticspro_post('can_import') === '1' ? 1 : 0,
                    'can_view_analytics' => analyticspro_post('can_view_analytics') === '1' ? 1 : 0,
                    'can_view_reports' => analyticspro_post('can_view_reports') === '1' ? 1 : 0,
                    'can_export' => analyticspro_post('can_export') === '0' ? 0 : 1,
                ]);
            analyticspro_set_flash('success', 'Permessi subutente aggiornati.');
            analyticspro_redirect('dashboard.php#subutenti');
        }
    }
} catch (Throwable $exception) {
    analyticspro_set_flash('danger', $exception->getMessage());
    analyticspro_redirect('dashboard.php');
}

$tenantId = analyticspro_current_tenant_id();
$selectedTenant = analyticspro_is_admin() ? (string) analyticspro_get('tenant_id', 'all') : (string) $tenantId;
$subuserPermissions = analyticspro_is_subuser() ? analyticspro_get_subuser_permissions((int) $user['id']) : null;
$pendingRegistrations = analyticspro_is_admin()
    ? analyticspro_db()->query("SELECT r.id AS request_id, u.* FROM registration_requests r JOIN users u ON u.id = r.user_id WHERE u.status = 'pending' ORDER BY r.created_at ASC")->fetchAll()
    : [];
$usersOverview = analyticspro_is_admin()
    ? analyticspro_db()->query("SELECT id, parent_user_id, role, nome, cognome, email, status, can_view_phone, created_at FROM users ORDER BY role, cognome, nome")->fetchAll()
    : [];
$subusers = analyticspro_is_main_user() ? analyticspro_fetch_subusers((int) $user['id']) : [];
$smtpSettings = analyticspro_smtp_settings();
$tenants = analyticspro_is_admin() ? analyticspro_fetch_tenants() : [];

analyticspro_render_header('Dashboard', ['app_assets' => true]);
?>
<div id="analyticspro-app"
     data-role="<?= analyticspro_h((string) $user['role']) ?>"
     data-tenant-id="<?= analyticspro_h((string) ($tenantId ?? '')) ?>"
     data-selected-tenant="<?= analyticspro_h($selectedTenant) ?>"
     data-can-view-phone="<?= analyticspro_tenant_phone_visibility($tenantId) ? '1' : '0' ?>"
     data-can-import="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_import']) ? '1' : '0' ?>"
     data-can-view-reports="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_reports']) ? '1' : '0' ?>"
     data-can-view-analytics="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_analytics']) ? '1' : '0' ?>"
     data-can-export="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_export']) ? '1' : '0' ?>"
     data-can-edit-all-markers="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_edit_all_markers']) ? '1' : '0' ?>"
     data-properties-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/properties.php')) ?>"
     data-property-update-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/update_property.php')) ?>"
     data-import-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/import.php')) ?>"
     data-import-progress-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/import_progress.php')) ?>"
     data-ade-jobs-endpoint="<?= analyticspro_h(analyticspro_base_url('api/admin/ade_jobs.php')) ?>">

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Dashboard AnalyticsPRO</h1>
            <p class="text-muted mb-0">Importa dati catastali, gestisci marker condivisi e monitora tenant e subutenti.</p>
        </div>
        <?php if (analyticspro_is_admin()): ?>
            <form method="get" class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 small text-muted">Vista admin</label>
                <select class="form-select" name="tenant_id" onchange="this.form.submit()">
                    <option value="all" <?= $selectedTenant === 'all' ? 'selected' : '' ?>>Tutti i dati di tutti gli utenti</option>
                    <?php foreach ($tenants as $tenant): ?>
                        <option value="<?= analyticspro_h((string) $tenant['id']) ?>" <?= $selectedTenant === (string) $tenant['id'] ? 'selected' : '' ?>><?= analyticspro_h(analyticspro_full_name($tenant)) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><div class="card metric-card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Immobili visibili</div><div class="display-6" data-kpi="properties">0</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card metric-card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Intestatari correnti</div><div class="display-6" data-kpi="owners">0</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card metric-card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Con telefono visibile</div><div class="display-6" data-kpi="phones">0</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card metric-card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Marker assegnati a me</div><div class="display-6" data-kpi="assigned">0</div></div></div></div>
    </div>

    <ul class="nav nav-tabs mb-3" id="dashboardTabs" role="tablist">
        <?php if (!analyticspro_is_subuser() || !empty($subuserPermissions['can_import'])): ?><li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-importa" type="button">Importa dati</button></li><?php endif; ?>
        <li class="nav-item"><button class="nav-link <?= analyticspro_is_subuser() && !empty($subuserPermissions['can_import']) ? '' : 'active' ?>" data-bs-toggle="tab" data-bs-target="#tab-mappa" type="button">Mappa</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-assegnati" type="button">Marker assegnati</button></li>
        <?php if (!analyticspro_is_subuser() || !empty($subuserPermissions['can_view_reports'])): ?><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-report" type="button">Report in griglia</button></li><?php endif; ?>
        <?php if (!analyticspro_is_subuser() || !empty($subuserPermissions['can_view_analytics'])): ?><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-analytics" type="button">Analitiche</button></li><?php endif; ?>
        <?php if (analyticspro_is_main_user()): ?><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-subutenti" type="button">Subutenti</button></li><?php endif; ?>
        <?php if (analyticspro_is_admin()): ?><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-admin" type="button">Configurazione admin</button></li><?php endif; ?>
    </ul>

    <div class="tab-content">
        <?php if (!analyticspro_is_subuser() || !empty($subuserPermissions['can_import'])): ?>
        <section class="tab-pane fade show active" id="tab-importa">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h5">Importa dati da CSV / Excel</h2>
                    <p class="text-muted small">Formati supportati: .csv, .xlsx, .xls. Il parsing lato client riusa SheetJS e invia al backend solo i record elaborati per il tenant corrente.</p>
                    <input id="import-files" type="file" class="form-control" multiple accept=".csv,.xlsx,.xls">
                    <div class="form-text">In caso di duplicato catastale con intestatario diverso verrà chiesta conferma prima dell'aggiornamento.</div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="tab-pane fade <?= analyticspro_is_subuser() && !empty($subuserPermissions['can_import']) ? '' : 'show active' ?>" id="tab-mappa">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3 gap-3 flex-wrap">
                        <div>
                            <h2 class="h5 mb-1">Mappa marker</h2>
                            <p class="text-muted small mb-0">Stato, colore, note e assegnazioni sono condivisi all'interno del tenant.</p>
                        </div>
                        <button class="btn btn-outline-primary btn-sm" id="refresh-map">Aggiorna dati</button>
                    </div>
                    <div id="map-container"></div>
                </div>
            </div>
        </section>

        <section class="tab-pane fade" id="tab-assegnati">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                        <div>
                            <h2 class="h5 mb-1">Marker assegnati</h2>
                            <p class="text-muted small mb-0">Vista focalizzata sui marker assegnati al subutente corrente o filtrabili dagli utenti principali/admin.</p>
                        </div>
                        <div class="d-flex gap-2">
                            <select id="assigned-subuser-filter" class="form-select form-select-sm"></select>
                            <button id="assigned-save" class="btn btn-primary btn-sm">Salva modifiche selezionate</button>
                        </div>
                    </div>
                    <table id="assigned-table" class="table table-striped table-hover w-100">
                        <thead></thead>
                        <tfoot></tfoot>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </section>

        <?php if (!analyticspro_is_subuser() || !empty($subuserPermissions['can_view_reports'])): ?>
        <section class="tab-pane fade" id="tab-report">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h5">Report in griglia</h2>
                    <p class="text-muted small">Vista generale di tutti gli immobili del tenant con filtri su ogni colonna, incluso colore e stato.</p>
                    <table id="report-table" class="table table-striped table-hover w-100">
                        <thead></thead>
                        <tfoot></tfoot>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!analyticspro_is_subuser() || !empty($subuserPermissions['can_view_analytics'])): ?>
        <section class="tab-pane fade" id="tab-analytics">
            <div class="row g-3">
                <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6">Contatti</h2><canvas id="chart-contacts"></canvas></div></div></div>
                <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6">Genere</h2><canvas id="chart-gender"></canvas></div></div></div>
                <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6">Età</h2><canvas id="chart-age"></canvas></div></div></div>
                <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6">Province</h2><canvas id="chart-province"></canvas></div></div></div>
                <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6">Comuni</h2><canvas id="chart-comune"></canvas></div></div></div>
                <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6">Categorie</h2><canvas id="chart-categoria"></canvas></div></div></div>
                <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6">Titolarità</h2><canvas id="chart-titolarita"></canvas></div></div></div>
            </div>
        </section>
        <?php endif; ?>

        <?php if (analyticspro_is_main_user()): ?>
        <section class="tab-pane fade" id="tab-subutenti">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm" id="subutenti">
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
        </section>
        <?php endif; ?>

        <?php if (analyticspro_is_admin()): ?>
        <section class="tab-pane fade" id="tab-admin">
            <div class="row g-4">
                <div class="col-12 col-xl-6">
                    <div class="card border-0 shadow-sm" id="configurazione">
                        <div class="card-body">
                            <h2 class="h5">Configurazione SMTP</h2>
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                                <input type="hidden" name="action" value="save_smtp">
                                <div class="col-md-6"><label class="form-label">Host</label><input class="form-control" name="smtp_host" value="<?= analyticspro_h((string) $smtpSettings['host']) ?>"></div>
                                <div class="col-md-3"><label class="form-label">Porta</label><input class="form-control" name="smtp_port" value="<?= analyticspro_h((string) $smtpSettings['port']) ?>"></div>
                                <div class="col-md-3"><label class="form-label">Sicurezza</label><select class="form-select" name="smtp_security"><option value="tls" <?= $smtpSettings['security'] === 'tls' ? 'selected' : '' ?>>TLS</option><option value="ssl" <?= $smtpSettings['security'] === 'ssl' ? 'selected' : '' ?>>SSL</option><option value="none" <?= $smtpSettings['security'] === 'none' ? 'selected' : '' ?>>Nessuna</option></select></div>
                                <div class="col-md-6"><label class="form-label">Utente SMTP</label><input class="form-control" name="smtp_user" value="<?= analyticspro_h((string) $smtpSettings['user']) ?>"></div>
                                <div class="col-md-6"><label class="form-label">Password SMTP</label><input type="password" class="form-control" name="smtp_pass" value="<?= analyticspro_h((string) $smtpSettings['pass']) ?>"></div>
                                <div class="col-md-6"><label class="form-label">Mittente email</label><input class="form-control" name="smtp_from_email" value="<?= analyticspro_h((string) $smtpSettings['from_email']) ?>"></div>
                                <div class="col-md-6"><label class="form-label">Nome mittente</label><input class="form-control" name="smtp_from_name" value="<?= analyticspro_h((string) $smtpSettings['from_name']) ?>"></div>
                                <div class="col-12"><label class="form-label">Email notifiche admin</label><input type="email" class="form-control" name="admin_notification_email" value="<?= analyticspro_h((string) analyticspro_system_config('admin_notification_email', '')) ?>"></div>
                                <div class="col-12 d-flex gap-2">
                                    <button class="btn btn-primary" type="submit">Salva configurazione</button>
                                </div>
                            </form>
                            <form method="post" class="mt-3">
                                <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                                <input type="hidden" name="action" value="test_smtp">
                                <button class="btn btn-outline-secondary" type="submit">Testa connessione</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h2 class="h5">Import cartografia ADE</h2>
                            <p class="text-muted small">Carica ZIP provinciali. Il worker CLI estrae ricorsivamente ZIP annidati, popola log e metriche di job ed è pronto per il passaggio PostGIS.</p>
                            <input id="ade-zips" type="file" class="form-control" accept=".zip" multiple>
                            <div class="form-text">Configura il database PostGIS nel file <code>.env</code>.</div>
                            <div id="ade-jobs" class="mt-3"></div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h2 class="h5">Registrazioni in attesa</h2>
                            <?php if (!$pendingRegistrations): ?><p class="text-muted mb-0">Nessuna registrazione pendente.</p><?php endif; ?>
                            <?php foreach ($pendingRegistrations as $pending): ?>
                                <form method="post" class="border rounded p-3 mb-3 d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
                                    <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="registration_decision">
                                    <input type="hidden" name="user_id" value="<?= analyticspro_h((string) $pending['id']) ?>">
                                    <div>
                                        <strong><?= analyticspro_h(analyticspro_full_name($pending)) ?></strong>
                                        <div class="text-muted small"><?= analyticspro_h((string) $pending['email']) ?></div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-success btn-sm" type="submit" name="decision" value="approve">Approva</button>
                                        <button class="btn btn-outline-danger btn-sm" type="submit" name="decision" value="reject">Rifiuta</button>
                                    </div>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12" id="utenti">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h2 class="h5">Utenti</h2>
                            <div class="table-responsive">
                                <table class="table table-striped align-middle">
                                    <thead><tr><th>Nome</th><th>Email</th><th>Ruolo</th><th>Tenant</th><th>Stato</th><th>Telefono visibile</th><th>Azione</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($usersOverview as $row): ?>
                                        <tr>
                                            <td><?= analyticspro_h(analyticspro_full_name($row)) ?></td>
                                            <td><?= analyticspro_h((string) $row['email']) ?></td>
                                            <td><?= analyticspro_h((string) $row['role']) ?></td>
                                            <td><?= analyticspro_h((string) ($row['parent_user_id'] ?: $row['id'])) ?></td>
                                            <td><?= analyticspro_h((string) $row['status']) ?></td>
                                            <td><?= !empty($row['can_view_phone']) ? 'Sì' : 'No' ?></td>
                                            <td>
                                                <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
                                                    <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="update_user">
                                                    <input type="hidden" name="target_user_id" value="<?= analyticspro_h((string) $row['id']) ?>">
                                                    <select class="form-select form-select-sm" name="status">
                                                        <option value="active" <?= $row['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                                        <option value="disabled" <?= $row['status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                                                    </select>
                                                    <select class="form-select form-select-sm" name="can_view_phone">
                                                        <option value="0" <?= empty($row['can_view_phone']) ? 'selected' : '' ?>>Telefono OFF</option>
                                                        <option value="1" <?= !empty($row['can_view_phone']) ? 'selected' : '' ?>>Telefono ON</option>
                                                    </select>
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
                </div>
            </div>
        </section>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="import-overlay" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body p-4 text-center">
                <div class="spinner-border text-primary mb-3"></div>
                <h2 class="h5">Non chiudere la pagina finché l'importazione non è completata</h2>
                <div class="progress my-3" style="height: 10px;"><div id="import-progress-bar" class="progress-bar" style="width:0%"></div></div>
                <p class="small text-muted mb-0" id="import-progress-text">Preparazione import...</p>
            </div>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(true); ?>
