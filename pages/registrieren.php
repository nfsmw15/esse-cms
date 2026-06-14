<?php

use Esse\Auth;
use Esse\Captcha;
use Esse\DB;
use Esse\Hooks;
use Esse\UserFields;

// If already logged in → redirect
if (Auth::check()) {
    header('Location: /'); exit;
}

// Check if registration is enabled
$ts      = DB::table('settings');
$enabled = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'registration_enabled'");

$tu     = DB::table('users');
$errors = [];
$done   = false;

$customFields = UserFields::forRegister();

if ($enabled === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $displayName = trim($_POST['display_name'] ?? '');
    $email       = trim($_POST['email']        ?? '');
    $password    = $_POST['password']          ?? '';
    $passwordC   = $_POST['password_confirm']  ?? '';
    $captchaA    = trim($_POST['captcha_answer'] ?? '');
    $honeypot    = trim($_POST[Captcha::HONEYPOT_FIELD] ?? '');

    if (!$displayName)                               $errors[] = 'Anzeigename ist Pflichtfeld.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = 'Ungültige E-Mail-Adresse.';
    if (strlen($password) < 10)                      $errors[] = 'Passwort muss mindestens 10 Zeichen haben.';
    if ($password !== $passwordC)                    $errors[] = 'Passwörter stimmen nicht überein.';
    if (!Captcha::verify($captchaA, $honeypot))      $errors[] = 'Sicherheitsfrage falsch beantwortet oder zu schnell abgeschickt. Bitte erneut versuchen.';

    $customValues = UserFields::collectFromPost($customFields, $_POST, $errors);

    if (empty($errors)) {
        $existing = DB::fetch("SELECT id FROM `{$tu}` WHERE email = ?", [$email]);
        if ($existing) {
            $errors[] = 'Diese E-Mail-Adresse ist bereits registriert.';
        } else {
            $newUserId = DB::insert($tu, [
                'display_name' => $displayName,
                'email'        => $email,
                'password'     => password_hash($password, PASSWORD_BCRYPT),
                'role'         => 'member',
                'active'       => 1,
            ]);
            UserFields::save($newUserId, $customFields, $customValues);
            $done = true;
        }
    }
}

$captchaQuestion = ($enabled === '1' && !$done) ? Captcha::challenge() : '';

$brandName   = 'ESSE CMS';
$brandSlogan = '';
if (defined('ESSE_DB_NAME')) {
    $brandRows = array_column(
        DB::fetchAll("SELECT `key`, `value` FROM `{$ts}` WHERE `key` IN ('site_name', 'site_slogan')"),
        'value',
        'key'
    );
    $brandName   = $brandRows['site_name']   ?? $brandName;
    $brandSlogan = $brandRows['site_slogan'] ?? '';
}

if (Hooks::has('auth.register.render')) {
    Hooks::fire('auth.register.render', [
        'registrationEnabled' => $enabled === '1',
        'done'                => $done,
        'errors'              => $errors,
        'csrfToken'           => Auth::csrfToken(),
        'captchaQuestion'     => $captchaQuestion,
        'honeypotField'       => Captcha::HONEYPOT_FIELD,
        'brandName'           => $brandName,
        'brandSlogan'         => $brandSlogan,
        'formData'            => [
            'display_name' => $_POST['display_name'] ?? '',
            'email'        => $_POST['email'] ?? '',
        ],
        'customFields'        => $customFields,
    ]);
    exit;
}

if ($enabled !== '1') {
    echo '<div class="alert alert-secondary">Registrierung ist derzeit deaktiviert.</div>';
    return;
}
?>
<div class="row justify-content-center">
    <div class="col-lg-5">
        <h1 class="h3 mb-4">Registrieren</h1>

        <?php if ($done): ?>
        <div class="alert alert-success">
            Account erstellt! Du kannst dich jetzt <a href="/login">anmelden</a>.
        </div>
        <?php else: ?>

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach ?>
        </div>
        <?php endif ?>

        <form method="post" action="/registrieren">
            <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
            <div class="mb-3">
                <label class="form-label">Anzeigename</label>
                <input type="text" name="display_name" class="form-control"
                       value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">E-Mail</label>
                <input type="email" name="email" class="form-control"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       autocomplete="email" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Passwort</label>
                <input type="password" name="password" class="form-control"
                       autocomplete="new-password" required>
                <div class="form-text">Mindestens 10 Zeichen</div>
            </div>
            <div class="mb-4">
                <label class="form-label">Passwort bestätigen</label>
                <input type="password" name="password_confirm" class="form-control"
                       autocomplete="new-password" required>
            </div>
            <?php foreach ($customFields as $field): ?>
            <?= UserFields::renderField($field, (string) ($_POST['cf_' . $field['field_key']] ?? '')) ?>
            <?php endforeach ?>
            <div class="mb-4">
                <label class="form-label"><?= htmlspecialchars($captchaQuestion) ?> = ?</label>
                <input type="text" name="captcha_answer" class="form-control" inputmode="numeric"
                       autocomplete="off" required>
            </div>
            <div class="esse-honeypot" aria-hidden="true">
                <label for="reg-website">Website</label>
                <input type="text" id="reg-website" name="<?= Captcha::HONEYPOT_FIELD ?>"
                       tabindex="-1" autocomplete="off">
            </div>
            <button class="btn btn-primary w-100">Account erstellen</button>
        </form>
        <div class="text-center mt-3">
            <a href="/login" class="text-secondary small">Bereits registriert? Anmelden</a>
        </div>
        <?php endif ?>
    </div>
</div>
