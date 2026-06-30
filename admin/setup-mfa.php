<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;
use Esse\Crypto;
use Esse\DB;
use Esse\QrCode;
use Esse\Totp;
use Esse\TwoFactor;

// Sanitize redirect — only allow same-site paths
function sanitizeRedirectMfaSetup(string $url): string
{
    if (!str_starts_with($url, '/') || str_starts_with($url, '//')) return '/';
    return $url;
}

function configuredLoginTargetMfaSetup(): string
{
    if (!defined('ESSE_DB_NAME')) return '/';
    $ts     = DB::table('settings');
    $target = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'login_homepage_slug'") ?: '/';
    return \Esse\PageTargets::redirectUrl((string) $target, '/');
}

// Guard: nur erreichbar mit aktivem Pflicht-Setup-Zwischenstand (nach korrektem Passwort),
// 10 Minuten gueltig (laenger als das 5-Minuten-Fenster bei der reinen Code-Eingabe in
// verify-2fa.php — Einrichtung braucht mehr Aufmerksamkeit als nur einen Code abzutippen).
$uid   = $_SESSION['esse_mfa_setup_uid'] ?? null;
$at    = (int) ($_SESSION['esse_mfa_setup_at'] ?? 0);
$level = $_SESSION['esse_mfa_setup_level'] ?? '';
if (!$uid || $at <= 0 || (time() - $at) > 600 || !in_array($level, ['2fa', 'passkey'], true)) {
    unset($_SESSION['esse_mfa_setup_uid'], $_SESSION['esse_mfa_setup_at'], $_SESSION['esse_mfa_setup_level'], $_SESSION['esse_mfa_setup_totp'], $_SESSION['esse_mfa_setup_backup_codes']);
    header('Location: /admin/login');
    exit;
}

$tu   = DB::table('users');
$user = DB::fetch("SELECT * FROM `{$tu}` WHERE id = ? AND active = 1", [$uid]);
if (!$user) {
    unset($_SESSION['esse_mfa_setup_uid'], $_SESSION['esse_mfa_setup_at'], $_SESSION['esse_mfa_setup_level'], $_SESSION['esse_mfa_setup_totp'], $_SESSION['esse_mfa_setup_backup_codes']);
    header('Location: /admin/login');
    exit;
}

$error          = '';
$totpSetup      = $_SESSION['esse_mfa_setup_totp'] ?? null;
$newBackupCodes = $_SESSION['esse_mfa_setup_backup_codes'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $action = $_POST['_action'] ?? '';

    if ($action === 'mfa_setup_totp_start' && $level === '2fa') {
        $secret = Totp::generateSecret();
        $_SESSION['esse_mfa_setup_totp'] = ['secret' => $secret, 'at' => time()];
        $totpSetup = $_SESSION['esse_mfa_setup_totp'];
        AuditLog::record('totp_setup_started', (int) $user['id'], $user['email']);
    } elseif ($action === 'mfa_setup_totp_confirm' && $level === '2fa') {
        $pending = $_SESSION['esse_mfa_setup_totp'] ?? null;
        $code    = trim($_POST['code'] ?? '');
        if (!$pending || (time() - $pending['at']) > 600) {
            $error = 'Setup-Sitzung abgelaufen. Bitte neu starten.';
            unset($_SESSION['esse_mfa_setup_totp']);
        } elseif (!Totp::verifyCode($pending['secret'], $code)) {
            $error     = 'Code ungültig. Bitte erneut versuchen.';
            $totpSetup = $pending;
        } else {
            $plainCodes = TwoFactor::generateBackupCodes();
            DB::update($tu, [
                'totp_secret'       => Crypto::encrypt($pending['secret']),
                'totp_enabled'      => 1,
                'totp_backup_codes' => TwoFactor::hashBackupCodes($plainCodes),
            ], ['id' => $user['id']]);
            unset($_SESSION['esse_mfa_setup_totp']);
            $totpSetup = null;
            $_SESSION['esse_mfa_setup_backup_codes'] = $plainCodes;
            $newBackupCodes = $plainCodes;
            AuditLog::record('2fa_enabled', (int) $user['id'], $user['email']);
        }
    } elseif ($action === 'mfa_setup_finish' && !empty($_SESSION['esse_mfa_setup_backup_codes'])) {
        $freshUser = DB::fetch("SELECT * FROM `{$tu}` WHERE id = ? AND active = 1", [$uid]);
        if ($freshUser && TwoFactor::isEnabled($freshUser)) {
            unset($_SESSION['esse_mfa_setup_uid'], $_SESSION['esse_mfa_setup_at'], $_SESSION['esse_mfa_setup_level'], $_SESSION['esse_mfa_setup_totp'], $_SESSION['esse_mfa_setup_backup_codes']);
            Auth::login($freshUser);
            AuditLog::record('login_success', (int) $freshUser['id'], $freshUser['email']);
            $redirect = trim($_POST['redirect'] ?? $_GET['redirect'] ?? '');
            header('Location: ' . ($redirect !== '' ? sanitizeRedirectMfaSetup($redirect) : configuredLoginTargetMfaSetup()));
            exit;
        }
        $error = 'Einrichtung nicht abgeschlossen.';
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
$csrf          = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sicherheits-Einrichtung erforderlich — <?= htmlspecialchars($brandName) ?></title>
    <link rel="stylesheet" href="/public/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/public/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/public/assets/css/auth.css">
</head>
<body class="d-flex align-items-center justify-content-center vh-100">
<div class="auth-box-wide">
    <div class="text-center mb-4">
        <div class="brand text-white"><?= htmlspecialchars($brandName) ?></div>
        <?php if ($brandSlogan !== ''): ?>
        <small class="text-secondary d-block"><?= htmlspecialchars($brandSlogan) ?></small>
        <?php endif ?>
        <small class="text-secondary">Sicherheits-Einrichtung erforderlich</small>
    </div>

    <div class="alert alert-info">
        <?= $level === 'passkey'
            ? 'Diese Seite verlangt einen Passkey für jeden Account, bevor du dich anmelden kannst.'
            : 'Diese Seite verlangt Zwei-Faktor-Authentifizierung (TOTP oder Passkey) für jeden Account, bevor du dich anmelden kannst.' ?>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif ?>

    <?php if ($newBackupCodes): ?>
    <div class="card mb-3">
        <div class="card-body">
            <div class="alert alert-warning mb-3">
                <strong>Backup-Codes — jetzt notieren!</strong>
                <p class="small mb-2">Diese Codes werden nur einmal angezeigt. Jeder Code funktioniert genau einmal als Ersatz für deinen TOTP-Code.</p>
                <div class="row row-cols-2 g-1 font-monospace small mb-0">
                    <?php foreach ($newBackupCodes as $bc): ?>
                    <div><?= htmlspecialchars($bc) ?></div>
                    <?php endforeach ?>
                </div>
            </div>
            <form method="post" action="/admin/setup-mfa<?= $redirectParam !== '' ? '?redirect=' . $redirectParam : '' ?>">
                <input type="hidden" name="_csrf"    value="<?= $csrf ?>">
                <input type="hidden" name="_action"  value="mfa_setup_finish">
                <input type="hidden" name="redirect" value="<?= $redirectParam ?>">
                <button class="btn btn-primary w-100">Weiter zum Login</button>
            </form>
        </div>
    </div>

    <?php elseif ($level === '2fa' && $totpSetup): ?>
    <?php
        $totpUri = Totp::provisioningUri($totpSetup['secret'], (string) ($user['email'] ?? ''), 'ESSE CMS');
        $totpQr  = QrCode::encode($totpUri, 'M');
    ?>
    <div class="card mb-3">
        <div class="card-body">
            <p class="mb-2">1. QR-Code mit deiner Authenticator-App scannen (z.B. Aegis, Google Authenticator):</p>
            <div class="bg-white d-inline-block p-2 rounded mb-3"><?= QrCode::toSvg($totpQr, 6) ?></div>
            <p class="small text-secondary">Oder Secret manuell eingeben: <code><?= htmlspecialchars($totpSetup['secret']) ?></code></p>

            <form method="post" action="/admin/setup-mfa<?= $redirectParam !== '' ? '?redirect=' . $redirectParam : '' ?>" class="mt-3">
                <input type="hidden" name="_csrf"   value="<?= $csrf ?>">
                <input type="hidden" name="_action" value="mfa_setup_totp_confirm">
                <p class="mb-2">2. 6-stelligen Code aus der App eingeben, um die Einrichtung abzuschließen:</p>
                <div class="d-flex gap-2">
                    <input type="text" name="code" class="form-control" inputmode="numeric" pattern="[0-9]{6}"
                           maxlength="6" autocomplete="one-time-code" autofocus required placeholder="000000">
                    <button class="btn btn-primary">Bestätigen</button>
                </div>
            </form>
        </div>
    </div>

    <?php else: ?>

    <?php if ($level === '2fa'): ?>
    <div class="card mb-3">
        <div class="card-body">
            <p class="mb-3">Authenticator-App (TOTP)</p>
            <form method="post" action="/admin/setup-mfa<?= $redirectParam !== '' ? '?redirect=' . $redirectParam : '' ?>">
                <input type="hidden" name="_csrf"   value="<?= $csrf ?>">
                <input type="hidden" name="_action" value="mfa_setup_totp_start">
                <button class="btn btn-outline-light w-100">TOTP einrichten</button>
            </form>
        </div>
    </div>
    <div class="d-flex align-items-center my-3">
        <hr class="border-secondary flex-grow-1 my-0">
        <span class="text-secondary small mx-2">oder</span>
        <hr class="border-secondary flex-grow-1 my-0">
    </div>
    <?php endif ?>

    <div class="card mb-3">
        <div class="card-body">
            <p class="mb-3">Passkey (Touch ID, Windows Hello, Sicherheitsschlüssel)</p>
            <button type="button" id="mfa-passkey-register-btn" class="btn btn-outline-light w-100">
                <i class="bi bi-fingerprint me-1"></i>Passkey registrieren
            </button>
            <div class="text-danger small mt-2 d-none" id="mfa-passkey-error"></div>
        </div>
    </div>

    <?php endif ?>

    <div class="text-center mt-3">
        <a href="/admin/login" class="text-secondary small">Abbrechen, zurück zum Login</a>
    </div>
</div>

<script type="application/json" id="admin-setup-mfa-config"><?= json_encode([
    'csrf'     => $csrf,
    'redirect' => $_GET['redirect'] ?? $_POST['redirect'] ?? '',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/public/assets/js/webauthn.js"></script>
<script src="/public/assets/js/admin-setup-mfa.js"></script>
</body>
</html>
