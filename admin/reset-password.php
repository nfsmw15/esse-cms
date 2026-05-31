<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;

if (Auth::check()) {
    header('Location: /admin');
    exit;
}

$token   = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errors  = [];
$success = false;

// Validate token
$tr      = DB::table('password_resets');
$reset   = $token ? DB::fetch("SELECT * FROM `{$tr}` WHERE token = ?", [$token]) : null;
$expired = $reset && (strtotime($reset['created_at']) < time() - 3600);
$valid   = $reset && !$expired;

if ($reset && $expired) {
    DB::delete($tr, ['token' => $token]);
    $reset = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $password = $_POST['password']         ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 10) {
        $errors[] = 'Passwort muss mindestens 10 Zeichen haben.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwörter stimmen nicht überein.';
    } else {
        $tu   = DB::table('users');
        $hash = password_hash($password, PASSWORD_BCRYPT);
        DB::update($tu, ['password' => $hash], ['email' => $reset['email']]);
        DB::delete($tr, ['token' => $token]);
        $success = true;
    }
}

?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Neues Passwort — ESSE CMS</title>
    <link rel="stylesheet" href="/public/vendor/bootstrap/css/bootstrap.min.css">
    <style>
        body { background: #0d0d0d; color: #e0e0e0; }
        .card { background: #1a1a1a; border: 1px solid #2d2d2d; }
        .brand { font-size: 1.4rem; font-weight: 700; letter-spacing: .1em; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center vh-100">
<div style="width:100%;max-width:400px;padding:1rem">
    <div class="text-center mb-4">
        <div class="brand text-white">ESSE CMS</div>
        <small class="text-secondary">Neues Passwort setzen</small>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success">
        Passwort erfolgreich geändert. Du kannst dich jetzt anmelden.
    </div>
    <a href="/admin/login" class="btn btn-primary w-100">Zum Login</a>

    <?php elseif (!$token || !$reset): ?>
    <div class="alert alert-danger">
        Dieser Link ist ungültig oder abgelaufen.
    </div>
    <a href="/admin/forgot-password" class="btn btn-outline-secondary w-100">
        Neuen Link anfordern
    </a>

    <?php else: ?>

    <?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach ?>
    </div>
    <?php endif ?>

    <div class="card">
        <div class="card-body p-4">
            <form method="post">
                <input type="hidden" name="_csrf"  value="<?= Auth::csrfToken() ?>">
                <input type="hidden" name="token"  value="<?= htmlspecialchars($token) ?>">
                <div class="mb-3">
                    <label class="form-label">Neues Passwort</label>
                    <input type="password" name="password" class="form-control"
                           autocomplete="new-password" autofocus required>
                    <div class="form-text">Mindestens 10 Zeichen</div>
                </div>
                <div class="mb-4">
                    <label class="form-label">Passwort bestätigen</label>
                    <input type="password" name="password_confirm" class="form-control"
                           autocomplete="new-password" required>
                </div>
                <button class="btn btn-primary w-100">Passwort speichern</button>
            </form>
        </div>
    </div>
    <?php endif ?>
</div>
</body>
</html>
