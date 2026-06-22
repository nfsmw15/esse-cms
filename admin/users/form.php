<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;
use Esse\DB;
use Esse\Flash;
use Esse\UserFields;

$userId ??= null;
$isEdit  = $userId !== null;
$tu      = DB::table('users');
$user    = null;
$errors  = [];

// Available roles depending on current user's role
// Start with built-in roles
$availableRoles = ['editor' => 'Editor', 'author' => 'Author', 'member' => 'Member'];
$canManageAdmins = Auth::can('manage_admins');

if ($canManageAdmins) {
    $availableRoles = ['admin' => 'Admin'] + $availableRoles;

    // Custom roles can carry high-impact permissions, so assigning them requires manage_admins.
    $tr = DB::table('roles');
    $customRoles = DB::fetchAll("SELECT slug, label FROM `{$tr}` WHERE is_default = 0 ORDER BY label ASC");
    foreach ($customRoles as $cr) {
        $availableRoles[$cr['slug']] = $cr['label'] . ' (Eigene Rolle)';
    }
}
if (Auth::role() === 'forge') {
    $availableRoles = ['forge' => 'Forge'] + $availableRoles;
}

if ($isEdit) {
    $user = DB::fetch("SELECT * FROM `{$tu}` WHERE id = ?", [$userId]);
    if (!$user) { http_response_code(404); echo '404'; exit; }

    if (!array_key_exists($user['role'], $availableRoles)) {
        http_response_code(403); echo '403 Forbidden'; exit;
    }
}

// -- POST handling --
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $action = $_POST['_action'] ?? 'save';

    // Deactivate / activate
    if (in_array($action, ['deactivate', 'activate'], true) && $isEdit) {
        if ($user['id'] === Auth::id()) {
            $errors[] = 'Du kannst deinen eigenen Account nicht deaktivieren.';
        } else {
            DB::update($tu, ['active' => $action === 'activate' ? 1 : 0], ['id' => $user['id']]);
            AuditLog::record(
                $action === 'activate' ? 'user_activated' : 'user_deactivated',
                Auth::id(),
                Auth::user()['email'] ?? null,
                ['target_user_id' => $user['id'], 'target_email' => $user['email']]
            );
            Flash::set('success', 'Status geändert.');
            header('Location: /admin/users');
            exit;
        }
    }

    if ($action === 'save') {
        $displayName = trim($_POST['display_name'] ?? '');
        $email       = trim($_POST['email']        ?? '');
        $password    = $_POST['password']          ?? '';
        $passwordC   = $_POST['password_confirm']  ?? '';
        $role        = $_POST['role']              ?? 'member';

        if (!$displayName) $errors[] = 'Anzeigename ist Pflichtfeld.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Ungültige E-Mail-Adresse.';
        if (!array_key_exists($role, $availableRoles)) $errors[] = 'Ungültige Rolle.';

        if (!$isEdit && !$password) $errors[] = 'Passwort ist Pflichtfeld.';
        if ($password && strlen($password) < 10) $errors[] = 'Passwort muss mindestens 10 Zeichen haben.';
        if ($password && $password !== $passwordC) $errors[] = 'Passwörter stimmen nicht überein.';

        // Forge-Warnung: Promoting to forge requires confirmation
        if ($role === 'forge' && (!$isEdit || $user['role'] !== 'forge')) {
            if (($_POST['forge_confirmed'] ?? '') !== '1') {
                $errors[] = '__forge_confirm__';
            }
        }

        // E-Mail uniqueness
        if (empty($errors)) {
            $existing = DB::fetch(
                "SELECT id FROM `{$tu}` WHERE email = ?" . ($isEdit ? " AND id != ?" : ''),
                $isEdit ? [$email, $userId] : [$email]
            );
            if ($existing) $errors[] = 'Diese E-Mail-Adresse ist bereits vergeben.';
        }

        $customFields = UserFields::all();
        $customValues = UserFields::collectFromPost($customFields, $_POST, $errors);

        if (empty($errors)) {
            $data = [
                'display_name' => $displayName,
                'email'        => $email,
                'role'         => $role,
            ];
            if ($password) {
                $data['password'] = password_hash($password, PASSWORD_BCRYPT);
            }

            if ($isEdit) {
                if ($role !== $user['role']) {
                    AuditLog::record(
                        'user_role_changed',
                        Auth::id(),
                        Auth::user()['email'] ?? null,
                        ['target_user_id' => $userId, 'target_email' => $email, 'old_role' => $user['role'], 'new_role' => $role]
                    );
                }
                DB::update($tu, $data, ['id' => $userId]);
                UserFields::save($userId, $customFields, $customValues);

                if ($canManageAdmins) {
                    // Save per-user permission overrides
                    $tup = DB::table('user_permissions');
                    $oldPerms = DB::fetchAll("SELECT permission_slug FROM `{$tup}` WHERE user_id = ? AND granted = 1", [$userId]);
                    $oldPerms = array_column($oldPerms, 'permission_slug');
                    sort($oldPerms);

                    $submittedPerms = [];
                    foreach ($_POST['user_permissions'] ?? [] as $slug) {
                        $slug = preg_replace('/[^a-z_]/', '', $slug);
                        if ($slug && array_key_exists($slug, Auth::PERMISSIONS)) {
                            $submittedPerms[] = $slug;
                        }
                    }

                    // Forge-exklusive Rechte koennen nur von Forge selbst vergeben ODER entzogen
                    // werden — ein Nicht-Forge-Admin darf bestehende Forge-Grants beim Speichern
                    // weder hinzufuegen noch versehentlich (weil das Checkbox-Feld fehlt) entfernen.
                    if (!Auth::meetsRole('forge')) {
                        $submittedPerms = array_diff($submittedPerms, Auth::FORGE_ONLY_PERMISSIONS);
                        $submittedPerms = array_merge($submittedPerms, array_intersect($oldPerms, Auth::FORGE_ONLY_PERMISSIONS));
                    }
                    $newPerms = array_values(array_unique($submittedPerms));

                    DB::query("DELETE FROM `{$tup}` WHERE user_id = ?", [$userId]);
                    foreach ($newPerms as $slug) {
                        DB::insert($tup, ['user_id' => $userId, 'permission_slug' => $slug, 'granted' => 1]);
                    }
                    sort($newPerms);

                    if ($oldPerms !== $newPerms) {
                        AuditLog::record(
                            'user_permissions_changed',
                            Auth::id(),
                            Auth::user()['email'] ?? null,
                            ['target_user_id' => $userId, 'target_email' => $email, 'old_permissions' => $oldPerms, 'new_permissions' => $newPerms]
                        );
                    }
                }

                Flash::set('success', 'Benutzer gespeichert.');
            } else {
                $newId = DB::insert($tu, array_merge($data, ['active' => 1]));
                UserFields::save($newId, $customFields, $customValues);
                AuditLog::record(
                    'user_created',
                    Auth::id(),
                    Auth::user()['email'] ?? null,
                    ['target_user_id' => $newId, 'target_email' => $email, 'role' => $role]
                );

                if ($canManageAdmins) {
                    // Save per-user permissions for new user
                    $isForge = Auth::meetsRole('forge');
                    $tup = DB::table('user_permissions');
                    foreach ($_POST['user_permissions'] ?? [] as $slug) {
                        $slug = preg_replace('/[^a-z_]/', '', $slug);
                        if (!$slug || !array_key_exists($slug, Auth::PERMISSIONS)) continue;
                        if (!$isForge && in_array($slug, Auth::FORGE_ONLY_PERMISSIONS, true)) continue;
                        DB::insert($tup, ['user_id' => $newId, 'permission_slug' => $slug, 'granted' => 1]);
                    }
                }

                Flash::set('success', "Benutzer '{$displayName}' erstellt.");
            }
            header('Location: /admin/users');
            exit;
        }

        $user = array_merge($user ?? [], compact('displayName', 'email', 'role'));
    }
}

$showForgeWarning = in_array('__forge_confirm__', $errors);
$errors = array_filter($errors, fn($e) => $e !== '__forge_confirm__');

$customFields = UserFields::all();
$customValues = $isEdit ? UserFields::valuesForUser($userId) : [];

$pageTitle = $isEdit ? 'Benutzer bearbeiten' : 'Neuer Benutzer';
$activeNav = 'users';

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="/admin/users" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach ?>
</div>
<?php endif ?>

<?php if ($showForgeWarning): ?>
<div class="alert alert-warning border border-warning">
    <h6 class="alert-heading"><i class="bi bi-exclamation-triangle-fill"></i> Achtung: Forge-Rechte vergeben</h6>
    <p class="mb-2">Ein <strong>Forge</strong>-Account hat uneingeschränkten Zugriff und kann:</p>
    <ul class="mb-3 small">
        <li>Deinen Account sperren oder degradieren</li>
        <li>Alle Inhalte und Einstellungen ändern</li>
        <li>PHP-Code auf dem Server ausführen</li>
        <li>Weitere Forge-Accounts anlegen</li>
    </ul>
    <form method="post">
        <input type="hidden" name="_csrf"           value="<?= Auth::csrfToken() ?>">
        <input type="hidden" name="_action"         value="save">
        <input type="hidden" name="display_name"    value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>">
        <input type="hidden" name="email"           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        <input type="hidden" name="role"            value="forge">
        <input type="hidden" name="password"        value="<?= htmlspecialchars($_POST['password'] ?? '') ?>">
        <input type="hidden" name="password_confirm" value="<?= htmlspecialchars($_POST['password_confirm'] ?? '') ?>">
        <input type="hidden" name="forge_confirmed" value="1">
        <div class="d-flex gap-2">
            <button class="btn btn-warning btn-sm">Ja, Forge-Rechte vergeben</button>
            <a href="/admin/users<?= $isEdit ? '/edit/'.$userId : '/create' ?>"
               class="btn btn-outline-secondary btn-sm">Abbrechen</a>
        </div>
    </form>
</div>
<?php endif ?>

<div class="row">
    <div class="col-lg-6">
        <form method="post">
            <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="_action" value="save">

            <div class="card">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Anzeigename</label>
                        <input type="text" name="display_name" class="form-control"
                               value="<?= htmlspecialchars($user['display_name'] ?? '') ?>"
                               required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">E-Mail</label>
                        <input type="email" name="email" class="form-control"
                               value="<?= htmlspecialchars($user['email'] ?? '') ?>"
                               autocomplete="off" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">
                            Passwort <?= $isEdit ? '<small class="text-secondary">(leer lassen = unverändert)</small>' : '' ?>
                        </label>
                        <input type="password" name="password" class="form-control"
                               autocomplete="new-password"
                               <?= !$isEdit ? 'required' : '' ?>>
                        <div class="form-text">Mindestens 10 Zeichen</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Passwort bestätigen</label>
                        <input type="password" name="password_confirm" class="form-control"
                               autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Rolle</label>
                        <select name="role" class="form-select">
                            <?php foreach ($availableRoles as $val => $label): ?>
                            <option value="<?= htmlspecialchars($val) ?>"
                                <?= ($user['role'] ?? 'member') === $val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                            <?php endforeach ?>
                        </select>
                    </div>

                    <?php if ($customFields): ?>
                    <hr class="border-secondary my-3">
                    <?php foreach ($customFields as $field): ?>
                    <?= UserFields::renderField($field, (string) ($customValues[$field['field_key']] ?? '')) ?>
                    <?php endforeach ?>
                    <hr class="border-secondary my-3">
                    <?php endif ?>

                    <?php
                    // Per-user permission overrides — only Forge and manage_admins users can set these
                    if ($canManageAdmins):
                        $tup = DB::table('user_permissions');
                        $userPerms = [];
                        if ($isEdit) {
                            $rows = DB::fetchAll("SELECT permission_slug FROM `{$tup}` WHERE user_id = ? AND granted = 1", [$userId]);
                            $userPerms = array_column($rows, 'permission_slug');
                        }
                    ?>
                    <div class="mb-3">
                        <label class="form-label">Zusätzliche Berechtigungen
                            <small class="text-secondary">(zusätzlich zur Rolle)</small>
                        </label>
                        <div class="card p-2 admin-panel-dark">
                            <?php foreach (Auth::PERMISSIONS as $slug => [$permLabel, $permDesc]):
                                $forgeOnly = in_array($slug, Auth::FORGE_ONLY_PERMISSIONS, true);
                                if ($forgeOnly && !Auth::meetsRole('forge')) continue;
                            ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="user_permissions[]"
                                       value="<?= htmlspecialchars($slug) ?>"
                                       id="up_<?= $slug ?>"
                                       <?= in_array($slug, $userPerms, true) ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="up_<?= $slug ?>"
                                       title="<?= htmlspecialchars($permDesc) ?>">
                                    <?= htmlspecialchars($permLabel) ?>
                                    <?php if ($forgeOnly): ?>
                                    <span class="badge bg-danger ms-1 badge-xxs">Gefährlich — nur Forge</span>
                                    <?php endif ?>
                                </label>
                            </div>
                            <?php endforeach ?>
                        </div>
                        <div class="form-text">Diese Rechte gelten zusätzlich zu den Rechten der Rolle.</div>
                    </div>
                    <?php endif ?>

                    <button class="btn btn-primary w-100">
                        <?= $isEdit ? 'Speichern' : 'Benutzer erstellen' ?>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <?php if ($isEdit && $user['id'] !== Auth::id()): ?>
    <div class="col-lg-4">
        <div class="card border-<?= $user['active'] ? 'danger' : 'success' ?>">
            <div class="card-header py-2">
                <small class="text-<?= $user['active'] ? 'danger' : 'success' ?>">Account-Status</small>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
                    <input type="hidden" name="_action" value="<?= $user['active'] ? 'deactivate' : 'activate' ?>">
                    <button class="btn btn-sm w-100 btn-outline-<?= $user['active'] ? 'danger' : 'success' ?>">
                        <?= $user['active'] ? '<i class="bi bi-person-x"></i> Account deaktivieren' : '<i class="bi bi-person-check"></i> Account aktivieren' ?>
                    </button>
                </form>
                <div class="form-text mt-2">
                    <?= $user['active']
                        ? 'Deaktivierte Accounts können sich nicht mehr anmelden.'
                        : 'Reaktivierung ermöglicht wieder den Login.' ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif ?>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
