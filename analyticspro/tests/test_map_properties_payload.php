<?php

declare(strict_types=1);

require __DIR__ . '/../includes/property_repository.php';

final class MapPayloadDatabase
{
    public array $queries = [];
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->pdo->sqliteCreateFunction('CONCAT', static fn (...$values): string => implode('', $values));
        $this->pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, nome TEXT, cognome TEXT);
            INSERT INTO users VALUES (7, 'Tenant', 'Uno'), (8, 'Altro', 'Tenant'), (10, 'Sub', 'Utente');
            CREATE TABLE properties (
                id INTEGER PRIMARY KEY, user_id INTEGER, provincia TEXT, comune TEXT, cod_catastale TEXT,
                sezione TEXT, foglio TEXT, particella TEXT, subalterno TEXT, rendita TEXT, categoria TEXT, indirizzo TEXT, civico TEXT,
                lat REAL, lng REAL, posizione_verificata INTEGER, coord_source TEXT, stato TEXT,
                stato_personalizzato TEXT, colore_marker TEXT, updated_at TEXT
            );
            INSERT INTO properties (id, user_id, comune, categoria, updated_at)
                VALUES (1, 7, 'Milano', 'A2', '2026-01-03'), (2, 7, 'Milano', 'A2', '2026-01-02'), (3, 8, 'Roma', 'A7', '2026-01-01');
            CREATE TABLE property_assignments (property_id INTEGER, subuser_id INTEGER, UNIQUE(property_id, subuser_id));
            INSERT INTO property_assignments VALUES (1, 10);
            CREATE TABLE property_notes (id INTEGER, property_id INTEGER, author_name_snapshot TEXT, testo TEXT, created_at TEXT);
            INSERT INTO property_notes VALUES (1, 1, 'Autore', 'Nota', '2026-01-01');
            CREATE TABLE property_owners (
                id INTEGER, property_id INTEGER, is_current INTEGER, tipo TEXT, nome_enc TEXT, cognome_enc TEXT,
                codice_fiscale_enc TEXT, indirizzo_enc TEXT, email_enc TEXT, telefono_enc TEXT,
                data_nascita TEXT, genere TEXT
            );
            INSERT INTO property_owners (id, property_id, is_current, tipo, nome_enc, cognome_enc, telefono_enc)
                VALUES (20, 1, 1, 'persona', 'Nome', 'Cognome', '123'), (21, 1, 0, 'persona', 'Storico', 'Storico', '456');");
    }

    public function prepare(string $sql): PDOStatement
    {
        $this->queries[] = $sql;
        return $this->pdo->prepare($sql);
    }
}

$db = new MapPayloadDatabase();
$permissionCalls = 0;
$visibilityCalls = 0;
$decryptCalls = 0;
$showPhone = false;

function analyticspro_db(): MapPayloadDatabase { return $GLOBALS['db']; }
function analyticspro_is_admin(): bool { return false; }
function analyticspro_tenant_phone_visibility(int $id): bool
{
    $GLOBALS['visibilityCalls']++;
    return $GLOBALS['showPhone'];
}
function analyticspro_get_subuser_permissions(int $id): array
{
    $GLOBALS['permissionCalls']++;
    return ['can_edit_all_markers' => false];
}
function analyticspro_fetch_subusers(int $id): array { return []; }
function analyticspro_decrypt(?string $value): string
{
    $GLOBALS['decryptCalls']++;
    return $value ?? '';
}
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$user = ['id' => 10, 'role' => 'subuser', 'parent_user_id' => 7];
$summary = analyticspro_fetch_properties_payload($user, 'all', null, null, true);
check(count($summary['properties']) === 2, 'Il riepilogo deve rispettare il tenant.');
check($summary['properties'][0]['can_edit'] && !$summary['properties'][1]['can_edit'], 'Permessi per assegnazione preservati.');
check(!isset($summary['properties'][0]['owners'], $summary['properties'][0]['notes']), 'Nessun dettaglio nel riepilogo.');
check(count($db->queries) === 2 && $decryptCalls === 0, 'Il riepilogo non deve leggere/decifrare owners o note.');
check(!str_contains($db->queries[0], 'p.*'), 'Colonne esplicite per la mappa.');
check(!str_contains($db->queries[0], 'DISTINCT'), 'Nessuna deduplicazione SQL non necessaria.');
check($permissionCalls === 1 && $visibilityCalls === 1, 'Permessi e visibilità recuperati una volta per utente/tenant.');

$db->queries = [];
$details = analyticspro_fetch_properties_payload($user, 'all', null, [1, 1, 3]);
check(count($details['properties']) === 1, 'Gli ID richiesti non devono aggirare il tenant.');
check(str_contains($db->queries[0], 'p.id IN (:property_id_0,:property_id_1)'), 'Query limitata agli ID richiesti e deduplicati.');
check($details['properties'][0]['owners'][0]['nome'] === 'Nome', 'Dettagli decifrati on-demand.');
check(count($details['properties'][0]['owners']) === 1, 'Non restituire intestatari storici.');
check(!isset($details['properties'][0]['owners'][0]['telefono']), 'Visibilità telefono rispettata.');
check($details['properties'][0]['notes'][0]['testo'] === 'Nota', 'Note disponibili nei dettagli.');

$showPhone = true;
$details = analyticspro_fetch_properties_payload($user, 'all', null, [1]);
check($details['properties'][0]['owners'][0]['telefono'] === '123', 'Telefono disponibile con permesso.');
$details = analyticspro_fetch_properties_payload($user, 'all', null, [3]);
check($details['properties'] === [], 'Richiesta a un altro tenant vuota.');
$db->queries = [];
check(analyticspro_fetch_properties_payload($user, 'all', null, [])['properties'] === [], 'Lista ID vuota non significa tutti.');
check($db->queries === [], 'Nessuna query per lista vuota.');
$assigned = analyticspro_fetch_properties_payload($user, 'assigned');
check(count($assigned['properties']) === 1 && $assigned['properties'][0]['id'] === 1, 'Filtro assegnazioni preservato senza DISTINCT.');

echo "PASS: map summary, scoped details and request-local permission reuse\n";
