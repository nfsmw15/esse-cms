<?php
/**
 * Profilseite — zugänglich für alle eingeloggten User.
 * Eingebunden via PageRenderer als PHP-Seite, oder direkt als Route.
 *
 * @var array  $esse_page
 * @var array  $esse_user
 */

use Esse\Auth;
use Esse\Crypto;
use Esse\DB;
use Esse\QrCode;
use Esse\Totp;
use Esse\TwoFactor;
use Esse\WebAuthn;

if (!Auth::check()) {
    header('Location: /login?redirect=/profil');
    exit;
}

$tu     = DB::table('users');
$errors = [];
$flash  = null;

// Setup-Zwischenstand (Secret bis zur Bestätigung nur in der Session, nie in der DB)
$totpSetup      = $_SESSION['totp_setup'] ?? null;
$newBackupCodes = null; // einmalig nach (Neu-)Generierung angezeigt

function profilReloadUser(string $tu): void
{
    Auth::login(DB::fetch("SELECT * FROM `{$tu}` WHERE id = ?", [Auth::id()]));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $action = $_POST['_action'] ?? 'update_profile';

    switch ($action) {

        // -- TOTP (Authenticator-App) — Setup, Bestätigung, Deaktivierung, Backup-Codes --

        case 'totp_setup_start':
            $secret = Totp::generateSecret();
            $_SESSION['totp_setup'] = ['secret' => $secret, 'at' => time()];
            $totpSetup = $_SESSION['totp_setup'];
            break;

        case 'totp_setup_cancel':
            unset($_SESSION['totp_setup']);
            $totpSetup = null;
            break;

        case 'totp_setup_confirm':
            $pending = $_SESSION['totp_setup'] ?? null;
            $code    = trim($_POST['code'] ?? '');
            if (!$pending || (time() - $pending['at']) > 600) {
                $errors[] = 'Setup-Sitzung abgelaufen. Bitte neu starten.';
                unset($_SESSION['totp_setup']);
            } elseif (!Totp::verifyCode($pending['secret'], $code)) {
                $errors[] = 'Code ungültig. Bitte erneut versuchen.';
                $totpSetup = $pending;
            } else {
                $plainCodes = TwoFactor::generateBackupCodes();
                DB::update($tu, [
                    'totp_secret'       => Crypto::encrypt($pending['secret']),
                    'totp_enabled'      => 1,
                    'totp_backup_codes' => TwoFactor::hashBackupCodes($plainCodes),
                ], ['id' => Auth::id()]);
                unset($_SESSION['totp_setup']);
                $totpSetup      = null;
                $newBackupCodes = $plainCodes;
                $flash = ['type' => 'success', 'message' => 'Zwei-Faktor-Authentifizierung aktiviert.'];
                profilReloadUser($tu);
            }
            break;

        case 'totp_disable':
            if (!password_verify((string) ($_POST['confirm_password'] ?? ''), Auth::user()['password'])) {
                $errors[] = 'Passwort falsch.';
            } else {
                DB::update($tu, [
                    'totp_enabled'      => 0,
                    'totp_secret'       => null,
                    'totp_backup_codes' => null,
                ], ['id' => Auth::id()]);
                $flash = ['type' => 'success', 'message' => 'Zwei-Faktor-Authentifizierung deaktiviert.'];
                profilReloadUser($tu);
            }
            break;

        case 'totp_regenerate_backup_codes':
            if (!password_verify((string) ($_POST['confirm_password'] ?? ''), Auth::user()['password'])) {
                $errors[] = 'Passwort falsch.';
            } else {
                $plainCodes = TwoFactor::generateBackupCodes();
                DB::update($tu, ['totp_backup_codes' => TwoFactor::hashBackupCodes($plainCodes)], ['id' => Auth::id()]);
                $newBackupCodes = $plainCodes;
                $flash = ['type' => 'success', 'message' => 'Neue Backup-Codes generiert — alte Codes sind ab sofort ungültig.'];
            }
            break;

        // -- Passkeys --

        case 'passkey_rename':
            $credId = (int) ($_POST['credential_id'] ?? 0);
            $label  = trim($_POST['label'] ?? '');
            if ($credId > 0 && $label !== '') {
                WebAuthn::renameCredential((int) Auth::id(), $credId, $label);
                $flash = ['type' => 'success', 'message' => 'Passkey umbenannt.'];
            }
            break;

        case 'passkey_remove':
            $credId = (int) ($_POST['credential_id'] ?? 0);
            if ($credId > 0 && WebAuthn::removeCredential((int) Auth::id(), $credId)) {
                $flash = ['type' => 'success', 'message' => 'Passkey entfernt.'];
            } else {
                $errors[] = 'Passkey nicht gefunden.';
            }
            break;

        // -- Stammdaten / Passwort --

        case 'update_profile':
        default:
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
                profilReloadUser($tu);
            }
            break;
    }
}

$user = Auth::user();
$totpEnabled  = TwoFactor::isEnabled($user);
$backupCodesLeft = $totpEnabled ? TwoFactor::remainingBackupCodes($user) : 0;
$passkeys     = WebAuthn::credentialsForUser((int) Auth::id());
$csrf         = Auth::csrfToken();
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

        <hr class="border-secondary my-5">
        <h2 class="h4 mb-4">Sicherheit</h2>

        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h3 class="h6 mb-1">Zwei-Faktor-Authentifizierung (TOTP)</h3>
                        <p class="text-secondary small mb-0">Zusätzlicher Code aus einer Authenticator-App beim Login mit Passwort.</p>
                    </div>
                    <span class="badge <?= $totpEnabled ? 'text-bg-success' : 'text-bg-secondary' ?>">
                        <?= $totpEnabled ? 'Aktiv' : 'Inaktiv' ?>
                    </span>
                </div>

                <?php if ($newBackupCodes): ?>
                <div class="alert alert-warning mt-3">
                    <strong>Backup-Codes — jetzt notieren!</strong>
                    <p class="small mb-2">Diese Codes werden nur einmal angezeigt. Jeder Code funktioniert genau einmal als Ersatz für deinen TOTP-Code, falls du keinen Zugriff auf deine Authenticator-App hast.</p>
                    <div class="row row-cols-2 g-1 font-monospace small mb-0">
                        <?php foreach ($newBackupCodes as $bc): ?>
                        <div><?= htmlspecialchars($bc) ?></div>
                        <?php endforeach ?>
                    </div>
                </div>
                <?php endif ?>

                <?php if ($totpEnabled): ?>
                    <p class="small text-secondary mt-3 mb-3">
                        Verbleibende Backup-Codes: <strong class="text-white"><?= $backupCodesLeft ?></strong>
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-light btn-sm" data-bs-toggle="modal" data-bs-target="#totpRegenModal">
                            Backup-Codes neu generieren
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#totpDisableModal">
                            Deaktivieren
                        </button>
                    </div>
                <?php elseif ($totpSetup): ?>
                    <?php
                        $totpUri = Totp::provisioningUri($totpSetup['secret'], (string) ($user['email'] ?? ''), 'ESSE CMS');
                        $totpQr  = QrCode::encode($totpUri, 'M');
                    ?>
                    <hr class="border-secondary my-3">
                    <p class="mb-2">1. QR-Code mit deiner Authenticator-App scannen (z.B. Aegis, Google Authenticator):</p>
                    <div class="bg-white d-inline-block p-2 rounded mb-3"><?= QrCode::toSvg($totpQr, 6) ?></div>
                    <p class="small text-secondary">Oder Secret manuell eingeben: <code><?= htmlspecialchars($totpSetup['secret']) ?></code></p>

                    <form method="post" action="/profil" class="mt-3">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="_action" value="totp_setup_confirm">
                        <p class="mb-2">2. 6-stelligen Code aus der App eingeben, um die Einrichtung abzuschließen:</p>
                        <div class="d-flex gap-2">
                            <input type="text" name="code" class="form-control" style="max-width:160px;letter-spacing:.2em"
                                   inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"
                                   placeholder="000000" required>
                            <button class="btn btn-primary">Bestätigen</button>
                        </div>
                    </form>
                    <form method="post" action="/profil" class="mt-2">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="_action" value="totp_setup_cancel">
                        <button class="btn btn-link btn-sm text-secondary px-0">Abbrechen</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="/profil" class="mt-3">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="_action" value="totp_setup_start">
                        <button class="btn btn-primary btn-sm">Einrichten</button>
                    </form>
                <?php endif ?>
            </div>
        </div>

        <?php if ($totpEnabled): ?>
        <div class="modal fade" id="totpDisableModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content bg-dark border-secondary">
                    <form method="post" action="/profil">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="_action" value="totp_disable">
                        <div class="modal-header border-secondary">
                            <h5 class="modal-title">Zwei-Faktor-Authentifizierung deaktivieren</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-secondary small">Bitte bestätige dein Passwort, um TOTP und alle Backup-Codes zu entfernen.</p>
                            <input type="password" name="confirm_password" class="form-control" autocomplete="current-password" required>
                        </div>
                        <div class="modal-footer border-secondary">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                            <button class="btn btn-danger">Deaktivieren</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="totpRegenModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content bg-dark border-secondary">
                    <form method="post" action="/profil">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="_action" value="totp_regenerate_backup_codes">
                        <div class="modal-header border-secondary">
                            <h5 class="modal-title">Neue Backup-Codes generieren</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-secondary small">Die alten Backup-Codes werden ungültig. Bitte bestätige dein Passwort.</p>
                            <input type="password" name="confirm_password" class="form-control" autocomplete="current-password" required>
                        </div>
                        <div class="modal-footer border-secondary">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                            <button class="btn btn-primary">Generieren</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif ?>

        <div class="card mb-4">
            <div class="card-body">
                <h3 class="h6 mb-1">Passkeys</h3>
                <p class="text-secondary small">
                    Mit einem Passkey meldest du dich komplett ohne Passwort an — Touch ID, Windows Hello
                    oder ein Sicherheitsschlüssel genügen. Ein Passkey ersetzt Passwort <em>und</em> die
                    Zwei-Faktor-Abfrage.
                </p>

                <?php if ($passkeys): ?>
                <ul class="list-group list-group-flush mb-3">
                    <?php foreach ($passkeys as $pk): ?>
                    <li class="list-group-item bg-transparent px-0">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <div><?= htmlspecialchars($pk['label'] !== '' ? $pk['label'] : 'Passkey') ?></div>
                                <div class="text-secondary small">
                                    Erstellt: <?= htmlspecialchars($pk['created_at']) ?>
                                    <?php if (!empty($pk['last_used_at'])): ?>
                                     · Zuletzt verwendet: <?= htmlspecialchars($pk['last_used_at']) ?>
                                    <?php endif ?>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#passkeyRemoveModal<?= (int) $pk['id'] ?>">
                                Entfernen
                            </button>
                        </div>
                        <form method="post" action="/profil" class="d-flex gap-2">
                            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                            <input type="hidden" name="_action" value="passkey_rename">
                            <input type="hidden" name="credential_id" value="<?= (int) $pk['id'] ?>">
                            <input type="text" name="label" class="form-control form-control-sm" style="max-width:240px"
                                   value="<?= htmlspecialchars($pk['label']) ?>" placeholder="Bezeichnung">
                            <button class="btn btn-sm btn-outline-light">Umbenennen</button>
                        </form>
                    </li>

                    <div class="modal fade" id="passkeyRemoveModal<?= (int) $pk['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content bg-dark border-secondary">
                                <form method="post" action="/profil">
                                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                                    <input type="hidden" name="_action" value="passkey_remove">
                                    <input type="hidden" name="credential_id" value="<?= (int) $pk['id'] ?>">
                                    <div class="modal-header border-secondary">
                                        <h5 class="modal-title">Passkey entfernen</h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p class="mb-0">
                                            „<?= htmlspecialchars($pk['label'] !== '' ? $pk['label'] : 'Passkey') ?>“ wirklich entfernen?
                                            Mit diesem Passkey ist danach keine Anmeldung mehr möglich.
                                        </p>
                                    </div>
                                    <div class="modal-footer border-secondary">
                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                                        <button class="btn btn-danger">Entfernen</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach ?>
                </ul>
                <?php else: ?>
                <p class="text-secondary small fst-italic">Noch keine Passkeys registriert.</p>
                <?php endif ?>

                <button type="button" id="passkey-add-btn" class="btn btn-primary btn-sm">
                    <i class="bi bi-fingerprint me-1"></i>Passkey hinzufügen
                </button>
                <div class="text-danger small mt-2 d-none" id="passkey-add-error"></div>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="profile-passkey-config"><?= json_encode(['csrf' => $csrf], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/public/assets/js/webauthn.js"></script>
<script src="/public/assets/js/profile-passkey.js"></script>
