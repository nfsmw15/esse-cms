<?php
/**
 * Profilseite — zugänglich für alle eingeloggten User.
 * Eingebunden via PageRenderer als PHP-Seite, oder direkt als Route.
 *
 * @var array  $esse_page
 * @var array  $esse_user
 */

use Esse\Auth;
use Esse\DB;

if (!Auth::check()) {
    header('Location: /admin/login?redirect=/profil');
    exit;
}

$tu     = DB::table('users');
$errors = [];
$flash  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $displayName = trim($_POST['display_name'] ?? '');
    $email       = trim($_POST['email']        ?? '');
    $password    = $_POST['password']          ?? '';
    $passwordC   = $_POST['password_confirm']  ?? '';

    if (!$displayName)                          $errors[] = 'Anzeigename ist Pflichtfeld.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Ungültige E-Mail-Adresse.';
    if ($password && strlen($password) < 10)    $errors[] = 'Passwort muss mindestens 10 Zeichen haben.';
    if ($password && $password !== $passwordC)  $errors[] = 'Passwörter stimmen nicht überein.';

    // E-Mail uniqueness (excluding own account)
    if (empty($errors)) {
        $dup = DB::fetch("SELECT id FROM `{$tu}` WHERE email = ? AND id != ?", [$email, Auth::id()]);
        if ($dup) $errors[] = 'Diese E-Mail-Adresse wird bereits verwendet.';
    }

    if (empty($errors)) {
        $data = ['display_name' => $displayName, 'email' => $email];
        if ($password) {
            $data['password'] = password_hash($password, PASSWORD_BCRYPT);
        }
        DB::update($tu, $data, ['id' => Auth::id()]);
        $flash = ['type' => 'success', 'message' => 'Profil gespeichert.'];

        // Reload user in session
        Auth::login(DB::fetch("SELECT * FROM `{$tu}` WHERE id = ?", [Auth::id()]));
    }
}

$user = Auth::user();
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <h1 class="h3 mb-4">Mein Profil</h1>

        <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['message']) ?></div>
        <?php endif ?>

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach ?>
        </div>
        <?php endif ?>

        <form method="post" action="/profil">
            <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">

            <div class="mb-3">
                <label class="form-label">Anzeigename</label>
                <input type="text" name="display_name" class="form-control"
                       value="<?= htmlspecialchars($user['display_name'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">E-Mail</label>
                <input type="email" name="email" class="form-control"
                       value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
            </div>
            <hr class="border-secondary my-4">
            <p class="text-secondary small">Passwort ändern — leer lassen um es beizubehalten</p>
            <div class="mb-3">
                <label class="form-label">Neues Passwort</label>
                <input type="password" name="password" class="form-control"
                       autocomplete="new-password"
                       placeholder="Leer lassen, um Passwort beizubehalten">
                <div class="form-text">Mindestens 10 Zeichen</div>
            </div>
            <div class="mb-4">
                <label class="form-label">Passwort bestätigen</label>
                <input type="password" name="password_confirm" class="form-control"
                       autocomplete="new-password">
            </div>
            <button class="btn btn-primary">Speichern</button>
            <a href="/" class="btn btn-outline-secondary ms-2">Abbrechen</a>
        </form>
    </div>
</div>
