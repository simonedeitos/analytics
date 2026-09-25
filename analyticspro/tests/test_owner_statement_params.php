<?php

declare(strict_types=1);

define('APP_DEBUG', true);

if (!function_exists('analyticspro_encrypt')) {
    function analyticspro_encrypt(?string $value): ?string
    {
        return $value;
    }
}

if (!function_exists('analyticspro_hash')) {
    function analyticspro_hash(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return hash('sha256', $value);
    }
}

require __DIR__ . '/../includes/importer.php';

$pass = true;
$errors = [];

$owner = [
    'tipo' => 'persona',
    'nome' => 'Mario',
    'cognome' => 'Rossi',
    'codice_fiscale' => 'RSSMRA80A01H501Z',
    'telefono' => '3331112222',
    'indirizzo' => 'Via Roma 1',
    'email' => 'mario@example.it',
    'data_nascita' => '1980-01-01',
    'luogo_nascita' => 'Brescia',
    'genere' => 'M',
    'quota' => '1/2',
    'titolarita' => 'Proprieta',
];

$insertParams = analyticspro_build_owner_statement_params($owner, 77, true);
if (!array_key_exists('property_id', $insertParams) || (int) $insertParams['property_id'] !== 77) {
    $pass = false;
    $errors[] = 'I parametri INSERT devono contenere property_id.';
}

$updateParams = analyticspro_build_owner_update_statement_params($owner, true);
if (array_key_exists('property_id', $updateParams)) {
    $pass = false;
    $errors[] = 'I parametri UPDATE non devono contenere property_id.';
}

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE property_owners (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            property_id INTEGER NOT NULL,
            tipo TEXT,
            nome_enc TEXT,
            cognome_enc TEXT,
            codice_fiscale_enc TEXT,
            telefono_enc TEXT,
            indirizzo_enc TEXT,
            email_enc TEXT,
            nome_hash TEXT,
            cognome_hash TEXT,
            codice_fiscale_hash TEXT,
            telefono_hash TEXT,
            data_nascita TEXT,
            luogo_nascita_enc TEXT,
            genere TEXT,
            quota TEXT,
            titolarita TEXT,
            is_current INTEGER NOT NULL
        )'
    );

    $insertOwnerSql = 'INSERT INTO property_owners (
            property_id, tipo, nome_enc, cognome_enc, codice_fiscale_enc, telefono_enc, indirizzo_enc, email_enc,
            nome_hash, cognome_hash, codice_fiscale_hash, telefono_hash, data_nascita, luogo_nascita_enc, genere, quota, titolarita, is_current
        ) VALUES (
            :property_id, :tipo, :nome_enc, :cognome_enc, :codice_fiscale_enc, :telefono_enc, :indirizzo_enc, :email_enc,
            :nome_hash, :cognome_hash, :codice_fiscale_hash, :telefono_hash, :data_nascita, :luogo_nascita_enc, :genere, :quota, :titolarita, 1
        )';
    analyticspro_debug_assert_sql_params_match($insertOwnerSql, $insertParams);
    $insertOwner = $pdo->prepare($insertOwnerSql);
    $insertOwner->execute($insertParams);

    $updateOwnerSql = 'UPDATE property_owners SET
            tipo = :tipo,
            nome_enc = :nome_enc,
            cognome_enc = :cognome_enc,
            codice_fiscale_enc = :codice_fiscale_enc,
            telefono_enc = :telefono_enc,
            indirizzo_enc = :indirizzo_enc,
            email_enc = :email_enc,
            nome_hash = :nome_hash,
            cognome_hash = :cognome_hash,
            codice_fiscale_hash = :codice_fiscale_hash,
            telefono_hash = :telefono_hash,
            data_nascita = :data_nascita,
            luogo_nascita_enc = :luogo_nascita_enc,
            genere = :genere,
            quota = :quota,
            titolarita = :titolarita
        WHERE id = :id AND is_current = 1';
    analyticspro_debug_assert_sql_params_match($updateOwnerSql, ['id' => 1] + $updateParams);
    $updateOwner = $pdo->prepare($updateOwnerSql);
    $updateOwner->execute(['id' => 1] + $updateParams);

    $newOwner = $owner;
    $newOwner['codice_fiscale'] = 'VRDLGU80A01H501K';
    $newOwner['nome'] = 'Luigi';
    $newOwner['cognome'] = 'Verdi';
    $insertOwner->execute(analyticspro_build_owner_statement_params($newOwner, 99, true));

    $rows = $pdo->query('SELECT id, property_id, quota, titolarita FROM property_owners ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 2) {
        $pass = false;
        $errors[] = 'I proprietari nuovi devono continuare a essere inseriti correttamente.';
    }
    if (($rows[0]['quota'] ?? null) !== '1/2' || ($rows[0]['titolarita'] ?? null) !== 'Proprieta') {
        $pass = false;
        $errors[] = 'L\'update del cointestatario con quota/titolarita non ha aggiornato i valori attesi.';
    }
    if ((int) ($rows[1]['property_id'] ?? 0) !== 99) {
        $pass = false;
        $errors[] = 'L\'insert del nuovo proprietario deve mantenere property_id.';
    }
} catch (Throwable $exception) {
    $pass = false;
    $errors[] = 'Errore esecuzione SQL parametri owner: ' . $exception->getMessage();
}

if ($pass) {
    echo "PASS: owner statement params UPDATE/INSERT OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
