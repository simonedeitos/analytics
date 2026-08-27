<?php
// Shared by all admin pages - included after bootstrap
analyticspro_require_auth();
if (!analyticspro_is_admin()) {
    analyticspro_set_flash('danger', 'Accesso non consentito: sezione riservata agli amministratori.');
    analyticspro_redirect('dashboard.php');
}
