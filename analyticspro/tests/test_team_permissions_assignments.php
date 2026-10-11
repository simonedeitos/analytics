<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/functions.php';
require dirname(__DIR__) . '/includes/property_repository.php';

final class TeamPermissionsDatabase extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $query = str_replace(' FOR UPDATE', '', $query);
            $query = str_replace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT(subuser_id) DO UPDATE SET', $query);
            $query = preg_replace('/VALUES\((can_[a-z_]+)\)/', 'excluded.$1', $query);
            $query = str_replace(
                'DELETE pa FROM property_assignments pa JOIN users u ON u.id = pa.subuser_id WHERE pa.property_id = :property_id AND',
                'DELETE FROM property_assignments WHERE property_id = :property_id AND subuser_id IN (SELECT u.id FROM users u WHERE',
                $query,
                $replaced
            );
            if ($replaced) {
                $query .= ')';
            }
        }
        return parent::prepare($query, $options);
    }
}

$dsn = getenv('ANALYTICSPRO_TEST_MYSQL_DSN') ?: 'sqlite::memory:';
$db = new TeamPermissionsDatabase($dsn, getenv('ANALYTICSPRO_TEST_MYSQL_USER') ?: 'root', getenv('ANALYTICSPRO_TEST_MYSQL_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
function analyticspro_db(): PDO { return $GLOBALS['db']; }
function check_team(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$temporary = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'TEMPORARY ' : '';
$db->exec("CREATE {$temporary}TABLE users (
    id INTEGER PRIMARY KEY, parent_user_id INTEGER, role VARCHAR(20), status VARCHAR(20),
    nome VARCHAR(50), cognome VARCHAR(50), email VARCHAR(100), can_view_phone INTEGER
)");
$db->exec("CREATE {$temporary}TABLE subuser_permissions (
    subuser_id INTEGER UNIQUE, can_edit_all_markers INTEGER, can_import INTEGER,
    can_view_analytics INTEGER, can_view_reports INTEGER, can_export INTEGER
)");
$db->exec("CREATE {$temporary}TABLE property_assignments (
    property_id INTEGER, subuser_id INTEGER, assigned_by INTEGER, UNIQUE(property_id, subuser_id)
)");
$db->exec("INSERT INTO users (id, parent_user_id, role, status, nome, cognome) VALUES
    (1, NULL, 'user', 'active', 'Owner', 'One'),
    (2, 1, 'subuser', 'active', 'Active', 'One'),
    (3, 1, 'subuser', 'disabled', 'Disabled', 'One'),
    (4, 9, 'subuser', 'active', 'Other', 'Tenant'),
    (5, 1, 'subuser', 'pending', 'Pending', 'One'),
    (6, 1, 'subuser', 'active', 'Active', 'Two')");

$defaults = analyticspro_normalize_subuser_permissions([]);
check_team($defaults === ['can_edit_all_markers' => 0, 'can_import' => 0, 'can_view_analytics' => 0, 'can_view_reports' => 0, 'can_export' => 1], 'Default operativi coerenti.');
check_team(analyticspro_get_subuser_permissions(2) === $defaults, 'Lettura senza riga permessi.');
$team = analyticspro_fetch_subusers(1);
check_team(count($team) === 4, 'Team include storico e pending.');
foreach ($team as $subuser) {
    check_team($subuser['can_export'] === 1 && $subuser['can_import'] === 0, 'Default UI e operativi uguali.');
}
check_team(count(analyticspro_fetch_subusers(1, true)) === 2, 'Candidati solo attivi dello stesso tenant.');

analyticspro_save_subuser_permissions($db, 2, ['can_export' => '1', 'can_import' => '1', 'can_view_reports' => 'true']);
check_team(analyticspro_get_subuser_permissions(2) === ['can_edit_all_markers' => 0, 'can_import' => 1, 'can_view_analytics' => 0, 'can_view_reports' => 0, 'can_export' => 1], 'Upsert inserisce riga mancante e normalizza tutti i flag.');
analyticspro_save_subuser_permissions($db, 2, ['can_edit_all_markers' => true, 'can_view_analytics' => 1, 'can_view_reports' => '1']);
check_team(analyticspro_get_subuser_permissions(2) === ['can_edit_all_markers' => 1, 'can_import' => 0, 'can_view_analytics' => 1, 'can_view_reports' => 1, 'can_export' => 0], 'Upsert aggiorna e deseleziona checkbox mancanti.');
check_team((int) $db->query('SELECT COUNT(*) FROM subuser_permissions')->fetchColumn() === 1, 'Upsert non duplica la riga.');

$db->exec('INSERT INTO property_assignments VALUES (10, 2, 1), (10, 3, 1), (11, 2, 1)');
$db->beginTransaction();
analyticspro_replace_active_property_assignments($db, 1, [10, 11], [6, '6'], 1);
$db->commit();
$rows = $db->query('SELECT property_id, subuser_id FROM property_assignments ORDER BY property_id, subuser_id')->fetchAll(PDO::FETCH_NUM);
check_team(array_map(static fn ($row) => array_map('intval', $row), $rows) === [[10, 3], [10, 6], [11, 6]], 'Gruppo aggiornato, storico disabilitato preservato, duplicati eliminati.');
foreach ([3, 4, 5, 1, 0, '6oops', []] as $invalid) {
    $db->beginTransaction();
    try {
        analyticspro_replace_active_property_assignments($db, 1, [10, 11], [2, $invalid], 1);
        throw new LogicException('Destinatario non valido accettato.');
    } catch (RuntimeException $exception) {
        check_team(str_contains($exception->getMessage(), 'subutente attivo dello stesso tenant'), 'Errore destinatario esplicito.');
        $db->rollBack();
    }
    check_team($db->query('SELECT property_id, subuser_id FROM property_assignments ORDER BY property_id, subuser_id')->fetchAll(PDO::FETCH_NUM) === $rows, 'Destinatari invalidi non modificano assegnazioni.');
}
$db->beginTransaction();
analyticspro_replace_active_property_assignments($db, 1, [10, 11], [], 1);
$db->commit();
check_team((int) $db->query('SELECT COUNT(*) FROM property_assignments')->fetchColumn() === 1, 'Svuotare picker preserva assegnazioni disabilitate.');

$js = file_get_contents(dirname(__DIR__) . '/assets/js/analyticspro.js');
check_team(str_contains($js, "canExport: root.dataset.canExport === '1'"), 'Export non invertito.');
check_team((bool) preg_match("/'#assigned-table',\\s*buildTableData\\(grouped, 'assigned'\\),\\s*state\\.canExport/", $js), 'Tabella Assegnati rispetta Export.');
check_team(str_contains($js, '(disabilitato, assegnato)') && str_contains($js, 'type="checkbox" checked disabled'), 'Picker mostra storico non selezionabile.');

$functions = '';
foreach (['renderAssignedTable', 'openAssignmentPicker', 'assignmentNames'] as $name) {
    check_team((bool) preg_match('/    function ' . $name . '\(.*?(?=\n    (?:async )?function )/s', $js, $match), 'Funzione JS presente: ' . $name);
    $functions .= $match[0] . "\n";
}
$javascriptTest = <<<'JS'
const assert = require('node:assert/strict');
const elements = new Map();
['assigned-table', 'assignment-picker-modal', 'assignment-picker-list', 'assignment-picker-meta', 'assignment-picker-save'].forEach(id => elements.set(id, {dataset: {}}));
const document = { getElementById: id => elements.get(id) };
const state = { role: 'user', canExport: false, subusers: [
    {id: 2, status: 'active', nome: 'Active', cognome: 'One'},
    {id: 3, status: 'disabled', nome: 'Disabled', cognome: 'One'},
    {id: 5, status: 'pending', nome: 'Pending', cognome: 'One'}
] };
let capturedExport;
let shown = 0;
const bootstrap = { Modal: { getOrCreateInstance: () => ({show() { shown++; }}) } };
function initDataTable(selector, rows, canExport) { capturedExport = canExport; }
function getAssignedPropertiesForDisplay() { return []; }
function groupPropertiesByUnit(rows) { return rows; }
function buildTableData(rows) { return rows; }
function ensureSharedModals() {}
function setModalError() {}
function unitLabel() { return 'Unit'; }
function escapeHtml(value) { return value.replaceAll('&', '&amp;').replaceAll('<', '&lt;'); }
const property = {id: 10, assignments: [
    {subuser_id: 2, subuser_name: 'Active One', subuser_status: 'active'},
    {subuser_id: 3, subuser_name: '<Disabled>', subuser_status: 'disabled'}
]};
function findPropertyGroupById() { return property; }
JS;
$javascriptTest .= "\n" . $functions . <<<'JS'
for (const value of [false, true]) {
    state.canExport = value;
    renderAssignedTable();
    assert.equal(capturedExport, value);
}
openAssignmentPicker(10);
let html = elements.get('assignment-picker-list').innerHTML;
assert.match(html, /value="2" checked/);
assert.match(html, /type="checkbox" checked disabled/);
assert.match(html, /&lt;Disabled> \(disabilitato, assegnato\)/);
assert.ok(!html.includes('value="3"') && !html.includes('value="5"'));
assert.deepEqual(assignmentNames(property), ['Active One', '<Disabled> (disabilitato)']);
state.subusers = [];
openAssignmentPicker(10);
html = elements.get('assignment-picker-list').innerHTML;
assert.match(html, /Nessun subutente attivo disponibile/);
assert.match(html, /disabilitato, assegnato/);
state.role = 'subuser';
openAssignmentPicker(10);
assert.equal(shown, 2);
console.log('PASS: assignment picker and assigned export JavaScript behavior');
JS;
$process = proc_open(['node'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
check_team(is_resource($process), 'Node disponibile per regressioni JS.');
fwrite($pipes[0], $javascriptTest);
fclose($pipes[0]);
check_team(proc_close($process) === 0, 'Regressioni JS passano.');

echo "PASS: Team permissions upsert/defaults, active assignment validation and export ({$db->getAttribute(PDO::ATTR_DRIVER_NAME)})\n";
