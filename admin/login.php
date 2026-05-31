<?php

declare(strict_types=1);

use Esse\Auth;

// Already logged in → redirect
if (Auth::check()) {
    header('Location: /admin');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) {
        $error = 'Ungültige Anfrage. Bitte die Seite neu laden.';
    } else {
        $login    = trim($_POST['login']    ?? '');
        $password = $_POST['password'] ?? '';

        if (Auth::attempt($login, $password)) {
            header('Location: /admin');
            exit;
        }
        $error = 'Benutzername/E-Mail oder Passwort falsch.';
    }
}

?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — ESSE CMS</title>
    <link rel="stylesheet" href="/public/vendor/bootstrap/css/bootstrap.min.css">
    <style>
        body { background: #0d0d0d; color: #e0e0e0; }
        .card { background: #1a1a1a; border: 1px solid #2d2d2d; }
        .form-control { background: #111; border-color: #333; color: #e0e0e0; }
        .form-control:focus { background: #111; border-color: #555; color: #e0e0e0; box-shadow: none; }
        .brand { font-size: 1.4rem; font-weight: 700; letter-spacing: .1em; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center vh-100">
<div style="width:100%;max-width:380px;padding:1rem">
    <div class="text-center mb-4">
        <div class="brand text-white">ESSE CMS</div>
        <small class="text-secondary">forge your web.</small>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif ?>

    <div class="card">
        <div class="card-body p-4">
            <form method="post" action="/admin/login">
                <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                <div class="mb-3">
                    <label class="form-label">E-Mail</label>
                    <input type="email" name="login" class="form-control" autocomplete="email" autofocus required>
                </div>
                <div class="mb-4">
                    <label class="form-label">Passwort</label>
                    <input type="password" name="password" class="form-control" autocomplete="current-password" required>
                </div>
                <button class="btn btn-primary w-100">Anmelden</button>
            </form>
        </div>
    </div>
    <div class="text-center mt-3">
        <a href="/admin/forgot-password" class="text-secondary small">Passwort vergessen?</a>
    </div>
</div>
</body>
</html>
