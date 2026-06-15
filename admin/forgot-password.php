<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;
use Esse\Captcha;
use Esse\DB;
use Esse\Hooks;
use Esse\Mailer;

// Already logged in → redirect
if (Auth::check()) {
    header('Location: /admin');
    exit;
}

$sent   = false;
$errors = [];
$now = time();
$_SESSION['password_reset_requests'] ??= [];
$_SESSION['password_reset_requests'] = array_values(array_filter(
    $_SESSION['password_reset_requests'],
    fn($ts) => is_int($ts) && $ts > $now - 900
));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $email    = trim($_POST['email'] ?? '');
    $captchaA = trim($_POST['captcha_answer'] ?? '');
    $honeypot = trim($_POST[Captcha::HONEYPOT_FIELD] ?? '');

    if (count($_SESSION['password_reset_requests']) >= 3) {
        $errors[] = 'Zu viele Anfragen. Bitte warte einige Minuten.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
    } elseif (!Captcha::verify($captchaA, $honeypot)) {
        $errors[] = 'Sicherheitsfrage falsch beantwortet oder zu schnell abgeschickt. Bitte erneut versuchen.';
    } else {
        $_SESSION['password_reset_requests'][] = $now;
        $tu   = DB::table('users');
        $user = DB::fetch("SELECT * FROM `{$tu}` WHERE email = ? AND active = 1", [$email]);

        if ($user) {
            // Delete old tokens for this email
            $tr = DB::table('password_resets');
            DB::delete($tr, ['email' => $email]);

            // Create new token
            $token = bin2hex(random_bytes(32));
            DB::insert($tr, ['token' => $token, 'email' => $email]);
            AuditLog::record('password_reset_requested', (int) $user['id'], $email);

            $ts      = DB::table('settings');
            $siteUrl = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'site_url'") ?? '';
            $siteName = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'site_name'") ?? 'ESSE CMS';
            $resetUrl = rtrim($siteUrl, '/') . '/neues-passwort?token=' . $token;

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

$captchaQuestion = $sent ? '' : Captcha::challenge();

$brandName   = 'ESSE CMS';
$brandSlogan = '';
if (defined('ESSE_DB_NAME')) {
    $ts        = DB::table('settings');
    $brandRows = array_column(
        DB::fetchAll("SELECT `key`, `value` FROM `{$ts}` WHERE `key` IN ('site_name', 'site_slogan')"),
        'value', 'key'
    );
    $brandName   = $brandRows['site_name']   ?? $brandName;
    $brandSlogan = $brandRows['site_slogan'] ?? '';
}

// Themes dürfen diese Seite im eigenen Design rendern (analog zu auth.login.render).
// /admin/forgot-password bleibt IMMER beim Standard-Rendering (Fail-Safe-Notausgang).
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (!str_starts_with($requestPath, '/admin') && Hooks::has('auth.forgot_password.render')) {
    Hooks::fire('auth.forgot_password.render', [
        'sent'            => $sent,
        'errors'          => $errors,
        'csrfToken'       => Auth::csrfToken(),
        'captchaQuestion' => $captchaQuestion,
        'honeypotField'   => Captcha::HONEYPOT_FIELD,
        'brandName'       => $brandName,
        'brandSlogan'     => $brandSlogan,
    ]);
    return;
}

?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Passwort zurücksetzen — ESSE CMS</title>
    <link rel="stylesheet" href="/public/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/public/assets/css/auth.css">
</head>
<body class="d-flex align-items-center justify-content-center vh-100">
<div class="auth-box-wide">
    <div class="text-center mb-4">
        <div class="brand text-white"><?= htmlspecialchars($brandName) ?></div>
        <?php if ($brandSlogan !== ''): ?>
        <small class="text-secondary d-block"><?= htmlspecialchars($brandSlogan) ?></small>
        <?php endif ?>
        <small class="text-secondary">Passwort zurücksetzen</small>
    </div>

    <?php if ($sent): ?>
    <div class="alert alert-success">
        Falls ein Account mit dieser E-Mail-Adresse existiert, wurde ein Reset-Link versendet.
        Bitte prüfe deinen Posteingang.
    </div>
    <a href="/login" class="btn btn-outline-secondary w-100">Zurück zum Login</a>

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
                <div class="mb-3">
                    <label class="form-label"><?= htmlspecialchars($captchaQuestion) ?> = ?</label>
                    <input type="text" name="captcha_answer" class="form-control" inputmode="numeric"
                           autocomplete="off" required>
                </div>
                <div class="esse-honeypot" aria-hidden="true">
                    <label for="fp-website">Website</label>
                    <input type="text" id="fp-website" name="<?= Captcha::HONEYPOT_FIELD ?>"
                           tabindex="-1" autocomplete="off">
                </div>
                <button class="btn btn-primary w-100">Link senden</button>
            </form>
        </div>
    </div>
    <div class="text-center mt-3">
        <a href="/login" class="text-secondary small">Zurück zum Login</a>
    </div>
    <?php endif ?>
</div>
</body>
</html>
