<?php

declare(strict_types=1);

define('ANALYTICSPRO_ROOT', dirname(__DIR__));
require_once ANALYTICSPRO_ROOT . '/includes/functions.php';
require_once ANALYTICSPRO_ROOT . '/includes/property_repository.php';
require_once ANALYTICSPRO_ROOT . '/includes/dashboard_stats.php';

function analyticspro_db(): PDO { return $GLOBALS['db']; }
function analyticspro_current_user(): ?array { return $GLOBALS['user']; }

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->sqliteCreateFunction('CONCAT', static fn (...$parts): string => implode('', $parts));
$db->exec("CREATE TABLE users (
    id INTEGER, parent_user_id INTEGER, role TEXT, nome TEXT, cognome TEXT, email TEXT,
    status TEXT, can_view_phone INTEGER, password_hash TEXT, remember_token TEXT,
    remember_token_expires_at TEXT, future_secret TEXT
);
INSERT INTO users VALUES
    (1, NULL, 'user', 'Tenant', 'Uno', 'tenant@example.test', 'active', 0, 'hash-fixture', 'token-fixture', '2099-01-01', 'secret-fixture'),
    (2, 1, 'subuser', 'Sub', 'Uno', 'sub@example.test', 'active', 0, 'hash-fixture', 'token-fixture', '2099-01-01', 'secret-fixture'),
    (3, 9, 'subuser', 'Altro', 'Tenant', 'other@example.test', 'active', 0, 'hash-fixture', 'token-fixture', '2099-01-01', 'secret-fixture');
CREATE TABLE subuser_permissions (subuser_id INTEGER, can_edit_all_markers INTEGER, can_import INTEGER, can_view_analytics INTEGER, can_view_reports INTEGER, can_export INTEGER);
INSERT INTO subuser_permissions VALUES (2, 0, 1, 1, 1, 1);
CREATE TABLE properties (id INTEGER, user_id INTEGER, comune TEXT, provincia TEXT, foglio TEXT, particella TEXT, categoria TEXT, lat REAL, lng REAL, created_at TEXT, updated_at TEXT);
INSERT INTO properties VALUES (10, 1, 'Roma', 'RM', '1', '2', 'A/2', NULL, NULL, '2026-01-01', '2026-01-01');
CREATE TABLE property_owners (id INTEGER, property_id INTEGER, is_current INTEGER);
CREATE TABLE property_notes (id INTEGER, property_id INTEGER, author_name_snapshot TEXT, testo TEXT, created_at TEXT);
CREATE TABLE property_assignments (property_id INTEGER, subuser_id INTEGER);
INSERT INTO property_assignments VALUES (10, 2);
CREATE TABLE import_batches (id INTEGER, user_id INTEGER, uploaded_by INTEGER, filename TEXT, status TEXT, processed_rows INTEGER, total_rows INTEGER, created_at TEXT, completed_at TEXT);");

foreach ([
    ['id' => 1, 'role' => 'user'],
    ['id' => 2, 'role' => 'subuser', 'parent_user_id' => 1],
    ['id' => 4, 'role' => 'admin'],
] as $user) {
    foreach ([
        analyticspro_fetch_properties_payload($user),
        analyticspro_fetch_dashboard_stats($user),
    ] as $payload) {
        $subusers = $payload['subusers'];
        if (count($subusers) !== 1 || $subusers[0]['id'] !== 2 || $subusers[0]['can_import'] !== 1) {
            throw new RuntimeException('Tenant scoping and collaborator permissions must be preserved.');
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['password_hash', 'remember_token', 'future_secret', 'hash-fixture', 'token-fixture', 'secret-fixture'] as $secret) {
            if (str_contains($json, $secret)) {
                throw new RuntimeException('Sensitive collaborator field exposed: ' . $secret);
            }
        }
    }
}
echo "PASS: property/dashboard collaborator payloads exclude credentials for all roles\n";
