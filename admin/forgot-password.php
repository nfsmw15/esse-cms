<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;
use Esse\Mailer;

// Already logged in → redirect
if (Auth::check()) {
    header('Location: /admin');
    exit;
}

$sent   = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
    } else {
        $tu   = DB::table('users');
        $user = DB::fetch("SELECT * FROM `{$tu}` WHERE email = ? AND active = 1", [$email]);

        if ($user) {
            // Delete old tokens for this email
            $tr = DB::table('password_resets');
            DB::delete($tr, ['email' => $email]);

            // Create new token
            $token = bin2hex(random_bytes(32));
            DB::insert($tr, ['token' => $token, 'email' => $email]);

            $ts      = DB::table('settings');
            $siteUrl = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'site_url'") ?? '';
            $siteName = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'site_name'") ?? 'ESSE CMS';
            $resetUrl = rtrim($siteUrl, '/') . '/admin/reset-password?token=' . $token;

            try {
                Mailer::send(
                    $email,
                    $user['display_name'],
                    'Passwort zurücksetzen — ' . $siteName,
                    "<p>Hallo {$user['display_name']},</p>"
                    . "<p>du hast eine Anfrage zum Zurücksetzen deines Passworts gestellt.</p>"
                    . "<p><a href=\"{$resetUrl}\">Passwort zurücksetzen</a></p>"
                    . "<p>Der Link ist 1 Stunde gültig.</p>"
                    . "<p>Falls du diese Anfrage nicht gestellt hast, kannst du diese E-Mail ignorieren.</p>"
                    . "<p>— {$siteName}</p>"
                );
            } catch (\Throwable $e) {
                // Log error but don't reveal details to user
                error_log('ESSE Mailer error: ' . $e->getMessage());
            }
        }

        // Always show success to avoid email enumeration
        $sent = true;
    }
}

?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Passwort zurücksetzen — ESSE CMS</title>
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
        <small class="text-secondary">Passwort zurücksetzen</small>
    </div>

    <?php if ($sent): ?>
    <div class="alert alert-success">
        Falls ein Account mit dieser E-Mail-Adresse existiert, wurde ein Reset-Link versendet.
        Bitte prüfe deinen Posteingang.
    </div>
    <a href="/admin/login" class="btn btn-outline-secondary w-100">Zurück zum Login</a>

    <?php else: ?>

    <?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach ?>
    </div>
    <?php endif ?>

    <div class="card">
        <div class="card-body p-4">
            <p class="text-secondary small mb-3">
                Gib deine E-Mail-Adresse ein. Du erhältst einen Link zum Zurücksetzen deines Passworts.
            </p>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                <div class="mb-3">
                    <label class="form-label">E-Mail</label>
                    <input type="email" name="email" class="form-control" autocomplete="username" autofocus required>
                </div>
                <button class="btn btn-primary w-100">Link senden</button>
            </form>
        </div>
    </div>
    <div class="text-center mt-3">
        <a href="/admin/login" class="text-secondary small">Zurück zum Login</a>
    </div>
    <?php endif ?>
</div>
</body>
</html>
