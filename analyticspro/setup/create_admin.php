<?php

declare(strict_types=1);

$passwordHash = null;
$sql = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim((string) ($_POST['nome'] ?? ''));
    $cognome = trim((string) ($_POST['cognome'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($nome === '' || $cognome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        $error = 'Compila tutti i campi e usa una password di almeno 8 caratteri.';
    } else {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $sql = sprintf(
            "INSERT INTO users (role, nome, cognome, email, password_hash, status, can_view_phone, must_change_password) VALUES ('admin', '%s', '%s', '%s', '%s', 'active', 1, 0);",
            addslashes($nome),
            addslashes($cognome),
            addslashes($email),
            addslashes($passwordHash)
        );
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AnalyticsPRO - Create Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="alert alert-danger fw-semibold">File usa e getta: genera solo la query SQL del primo admin. Elimina immediatamente questo file dopo l'uso.</div>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <h1 class="h3 mb-3">Generatore SQL primo admin</h1>
            <?php if ($error): ?><div class="alert alert-warning"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <form method="post" class="row g-3">
                <div class="col-md-6"><label class="form-label">Nome</label><input class="form-control" name="nome" required></div>
                <div class="col-md-6"><label class="form-label">Cognome</label><input class="form-control" name="cognome" required></div>
                <div class="col-12"><label class="form-label">Email</label><input type="email" class="form-control" name="email" required></div>
                <div class="col-12"><label class="form-label">Password</label><input type="password" class="form-control" name="password" minlength="8" required></div>
                <div class="col-12"><button class="btn btn-primary" type="submit">Genera INSERT</button></div>
            </form>
            <?php if ($sql): ?>
                <hr>
                <p class="fw-semibold mb-2">Esegui manualmente questa query sul database MySQL/MariaDB:</p>
                <pre class="bg-dark text-white p-3 rounded small"><?= htmlspecialchars($sql, ENT_QUOTES, 'UTF-8') ?></pre>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
