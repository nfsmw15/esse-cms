<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;
use Esse\Captcha;
use Esse\DB;
use Esse\EmailVerification;
use Esse\Hooks;
use Esse\RateLimit;

if (Auth::check()) {
    header('Location: /admin');
    exit;
}

$tv = DB::table('email_verifications');
$tu = DB::table('users');
$ts = DB::table('settings');

// --- GET ?token=... : direkte Verifizierung, kein Formular/CSRF noetig (der Klick auf den
// Mail-Link IST die Aktion). Rate-Limit vor DB-Lookup und Audit-Log, analog zu
// admin/reset-password.php — verhindert, dass zufaelliges Token-Raten Log/DB spammt.
$token = trim($_GET['token'] ?? '');

$viewBucket  = 'verify_email_view:' . RateLimit::clientIp();
$rateLimited = $token !== '' && RateLimit::tooMany($viewBucket, 20, 600);

$verification = ($token !== '' && !$rateLimited)
    ? DB::fetch("SELECT * FROM `{$tv}` WHERE token = ?", [$token])
    : null;
$expired = $verification && (strtotime($verification['created_at']) < time() - EmailVerification::TTL_SECONDS);
$valid   = $verification && !$expired;

if ($token !== '' && !$rateLimited) {
    RateLimit::hit($viewBucket);
}

if ($token !== '' && !$verification && !$rateLimited) {
    AuditLog::record('email_verification_invalid_token', null, null, ['reason' => 'not_found']);
}

if ($verification && $expired) {
    AuditLog::record('email_verification_invalid_token', null, $verification['email'], ['reason' => 'expired']);
    DB::delete($tv, ['token' => $token]);
    $verification = null;
}

$verifiedNow = false;
$pendingApprovalAfterVerify = false;
if ($token !== '' && $valid) {
    DB::update($tu, ['email_verified_at' => date('Y-m-d H:i:s')], ['id' => $verification['user_id']]);
    DB::delete($tv, ['token' => $token]);
    AuditLog::record('email_verified', (int) $verification['user_id'], $verification['email']);
    $verifiedNow = true;

    if (DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'registration_requires_approval'") === '1') {
        $freshUser = DB::fetch("SELECT approved_at FROM `{$tu}` WHERE id = ?", [$verification['user_id']]);
        $pendingApprovalAfterVerify = empty($freshUser['approved_at'] ?? null);
    }
}

$tokenInvalid = $token !== '' && !$verifiedNow;

// --- Anfordern/erneut senden (GET ohne Token, oder POST) ---
$sent            = false;
$errors          = [];
$rateLimitBucket = 'email_verify_request:' . RateLimit::clientIp();
$prefillEmail    = trim($_GET['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $email        = trim($_POST['email'] ?? '');
    $captchaA     = trim($_POST['captcha_answer'] ?? '');
    $honeypot     = trim($_POST[Captcha::HONEYPOT_FIELD] ?? '');
    $prefillEmail = $email;

    if (RateLimit::tooMany($rateLimitBucket, 3, 900)) {
        AuditLog::record('rate_limit_locked', null, $email ?: null, ['bucket' => 'email_verify_request']);
        $errors[] = 'Zu viele Anfragen. Bitte warte einige Minuten.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
    } elseif (!Captcha::verify($captchaA, $honeypot)) {
        $errors[] = 'Sicherheitsfrage falsch beantwortet oder zu schnell abgeschickt. Bitte erneut versuchen.';
    } else {
        RateLimit::hit($rateLimitBucket);

        // Nur unverifizierte, aktive Accounts bekommen tatsaechlich eine Mail — Antwort ist
        // trotzdem immer identisch (Anti-Enumeration), analog admin/forgot-password.php.
        $user = DB::fetch(
            "SELECT * FROM `{$tu}` WHERE email = ? AND active = 1 AND email_verified_at IS NULL",
            [$email]
        );

        if ($user) {
            $newToken = EmailVerification::createToken((int) $user['id'], $email);
            AuditLog::record('email_verification_resent', (int) $user['id'], $email);
            EmailVerification::sendMail($email, $user['display_name'], $newToken);
        }

        $sent = true;
    }
}

$captchaQuestion = ($sent || $verifiedNow) ? '' : Captcha::challenge();

$brandName   = 'ESSE CMS';
$brandSlogan = '';
if (defined('ESSE_DB_NAME')) {
    $brandRows = array_column(
        DB::fetchAll("SELECT `key`, `value` FROM `{$ts}` WHERE `key` IN ('site_name', 'site_slogan')"),
        'value', 'key'
    );
    $brandName   = $brandRows['site_name']   ?? $brandName;
    $brandSlogan = $brandRows['site_slogan'] ?? '';
}

// Themes duerfen diese Seite im eigenen Design rendern (analog zu auth.login.render).
// /admin/verify-email bleibt IMMER beim Standard-Rendering (Fail-Safe-Notausgang).
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (!str_starts_with($requestPath, '/admin') && Hooks::has('auth.verify_email.render')) {
    Hooks::fire('auth.verify_email.render', [
        'verifiedNow'     => $verifiedNow,
        'pendingApprovalAfterVerify' => $pendingApprovalAfterVerify,
        'tokenInvalid'    => $tokenInvalid,
        'sent'            => $sent,
        'errors'          => $errors,
        'prefillEmail'    => $prefillEmail,
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
    <title>E-Mail bestätigen — ESSE CMS</title>
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
        <small class="text-secondary">E-Mail-Adresse bestätigen</small>
    </div>

    <?php if ($verifiedNow && $pendingApprovalAfterVerify): ?>
    <div class="alert alert-success">
        E-Mail-Adresse bestätigt! Dein Account wartet jetzt zusätzlich auf Freigabe durch einen
        Administrator. Du wirst per E-Mail informiert, sobald du dich einloggen kannst.
    </div>

    <?php elseif ($verifiedNow): ?>
    <div class="alert alert-success">
        E-Mail-Adresse bestätigt! Du kannst dich jetzt anmelden.
    </div>
    <a href="/login" class="btn btn-primary w-100">Zum Login</a>

    <?php elseif ($tokenInvalid): ?>
    <div class="alert alert-danger">
        Dieser Link ist ungültig oder abgelaufen.
    </div>
    <p class="text-secondary small">Fordere unten eine neue Bestätigungs-Mail an.</p>
    <?php endif ?>

    <?php if (!$verifiedNow): ?>

    <?php if ($sent): ?>
    <div class="alert alert-success">
        Falls ein unbestätigtes Konto mit dieser E-Mail-Adresse existiert, wurde eine neue
        Bestätigungs-Mail versendet. Bitte prüfe deinen Posteingang.
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
                Gib deine E-Mail-Adresse ein. Falls dein Konto noch unbestätigt ist, erhältst du
                einen neuen Bestätigungs-Link.
            </p>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                <div class="mb-3">
                    <label class="form-label">E-Mail</label>
                    <input type="email" name="email" class="form-control" autocomplete="username"
                           value="<?= htmlspecialchars($prefillEmail) ?>" autofocus required>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= htmlspecialchars($captchaQuestion) ?> = ?</label>
                    <input type="text" name="captcha_answer" class="form-control" inputmode="numeric"
                           autocomplete="off" required>
                </div>
                <div class="esse-honeypot" aria-hidden="true">
                    <label for="ve-website">Website</label>
                    <input type="text" id="ve-website" name="<?= Captcha::HONEYPOT_FIELD ?>"
                           tabindex="-1" autocomplete="off">
                </div>
                <button class="btn btn-primary w-100">Bestätigungs-Mail senden</button>
            </form>
        </div>
    </div>
    <div class="text-center mt-3">
        <a href="/login" class="text-secondary small">Zurück zum Login</a>
    </div>
    <?php endif ?>
    <?php endif ?>
</div>
</body>
</html>
