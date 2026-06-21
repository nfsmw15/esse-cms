<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;
use Esse\DB;
use Esse\RateLimit;
use Esse\TwoFactor;
use Esse\Totp;

// Sanitize redirect — only allow same-site paths
function sanitizeRedirect2fa(string $url): string
{
    if (!str_starts_with($url, '/') || str_starts_with($url, '//')) return '/';
    return $url;
}

function configuredLoginTarget2fa(): string
{
    if (!defined('ESSE_DB_NAME')) return '/';
    $ts     = DB::table('settings');
    $target = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'login_homepage_slug'") ?: '/';
    return \Esse\PageTargets::redirectUrl((string) $target, '/');
}

// Guard: nur erreichbar mit aktivem 2FA-Zwischenstand (nach korrektem Passwort), und
// nur 5 Minuten lang gültig — danach zurück zum normalen Login.
$uid = $_SESSION['esse_2fa_uid'] ?? null;
$at  = (int) ($_SESSION['esse_2fa_at'] ?? 0);
if (!$uid || $at <= 0 || (time() - $at) > 300) {
    unset($_SESSION['esse_2fa_uid'], $_SESSION['esse_2fa_at']);
    header('Location: /admin/login');
    exit;
}

$tu   = DB::table('users');
$user = DB::fetch("SELECT * FROM `{$tu}` WHERE id = ? AND active = 1", [$uid]);
if (!$user || !TwoFactor::isEnabled($user)) {
    unset($_SESSION['esse_2fa_uid'], $_SESSION['esse_2fa_at']);
    header('Location: /admin/login');
    exit;
}

$error = '';
$rateLimitBucket = '2fa:' . $uid;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) {
        $error = 'Ungültige Anfrage. Bitte die Seite neu laden.';
    } elseif (RateLimit::tooMany($rateLimitBucket, 5, 60)) {
        $error = 'Zu viele Fehlversuche. Bitte kurz warten und erneut versuchen.';
        AuditLog::record('2fa_locked', (int) $user['id'], $user['email']);
    } else {
        $code    = trim($_POST['code'] ?? '');
        $isValid = false;

        if ($code !== '') {
            $secret = $user['totp_secret'] ? \Esse\Crypto::decrypt($user['totp_secret']) : '';
            if ($secret !== '' && Totp::verifyCode($secret, $code)) {
                $isValid = true;
            } elseif (TwoFactor::verifyBackupCode($user, $code)) {
                $isValid = true;
            }
        }

        if ($isValid) {
            unset($_SESSION['esse_2fa_uid'], $_SESSION['esse_2fa_at']);
            RateLimit::clear($rateLimitBucket);
            Auth::login($user);
            AuditLog::record('login_success', (int) $user['id'], $user['email']);
            $redirect = trim($_POST['redirect'] ?? $_GET['redirect'] ?? '');
            header('Location: ' . ($redirect !== '' ? sanitizeRedirect2fa($redirect) : configuredLoginTarget2fa()));
            exit;
        }

        RateLimit::hit($rateLimitBucket);
        AuditLog::record('2fa_failed', (int) $user['id'], $user['email']);
        $error = 'Code ungültig oder abgelaufen.';
    }
}

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

$redirectParam = htmlspecialchars($_GET['redirect'] ?? $_POST['redirect'] ?? '');
?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bestätigung erforderlich — <?= htmlspecialchars($brandName) ?></title>
    <link rel="stylesheet" href="/public/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/public/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/public/assets/css/auth.css">
</head>
<body class="d-flex align-items-center justify-content-center vh-100">
<div class="auth-box">
    <div class="text-center mb-4">
        <div class="brand text-white"><?= htmlspecialchars($brandName) ?></div>
        <?php if ($brandSlogan !== ''): ?>
        <small class="text-secondary"><?= htmlspecialchars($brandSlogan) ?></small>
        <?php endif ?>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif ?>

    <div class="card">
        <div class="card-body p-4">
            <p class="text-center mb-3">
                <i class="bi bi-shield-lock display-6 text-secondary"></i>
            </p>
            <p class="text-center text-secondary small mb-4">
                Zwei-Faktor-Authentifizierung aktiv — bitte Code aus deiner Authenticator-App
                eingeben (oder einen Backup-Code verwenden).
            </p>
            <form method="post" action="/admin/verify-2fa<?= $redirectParam !== '' ? '?redirect=' . $redirectParam : '' ?>">
                <input type="hidden" name="_csrf"    value="<?= Auth::csrfToken() ?>">
                <input type="hidden" name="redirect" value="<?= $redirectParam ?>">
                <div class="mb-4">
                    <label class="form-label">Code</label>
                    <input type="text" name="code" class="form-control text-center auth-code-input"
                           inputmode="text" autocomplete="one-time-code" autofocus required
                           placeholder="000000">
                    <div class="form-text">6-stelliger Code aus der App, oder ein Backup-Code (z.B. „ab3de-fg7hk")</div>
                </div>
                <button class="btn btn-primary w-100">Bestätigen</button>
            </form>
        </div>
    </div>
    <div class="text-center mt-3">
        <a href="/admin/login" class="text-secondary small">Abbrechen, zurück zum Login</a>
    </div>
</div>
</body>
</html>
