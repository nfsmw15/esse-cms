<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;

// Only Forge or users with manage_admins can manage roles
if (!Auth::meetsRole('forge') && !Auth::can('manage_admins')) {
    http_response_code(403); echo '403 Forbidden'; exit;
}

$tr  = DB::table('roles');
$tp  = DB::table('permissions');
$trp = DB::table('role_permissions');

$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// -- POST actions --
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $action = $_POST['_action'] ?? '';

    // Save permission assignments for a role
    if ($action === 'save_role_permissions') {
        $roleId  = (int) ($_POST['role_id'] ?? 0);
        $role    = DB::fetch("SELECT * FROM `{$tr}` WHERE id = ?", [$roleId]);

        if ($role && !$role['is_default']) {
            // Custom role: full control
            DB::query("DELETE FROM `{$trp}` WHERE role_id = ?", [$roleId]);
            foreach ($_POST['permissions'] ?? [] as $slug) {
                $slug = preg_replace('/[^a-z_]/', '', $slug);
                DB::query(
                    "INSERT IGNORE INTO `{$trp}` (role_id, permission_id)
                     SELECT ?, p.id FROM `{$tp}` p WHERE p.slug = ?",
                    [$roleId, $slug]
                );
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => "Rechte für Rolle '{$role['label']}' gespeichert."];
        } elseif ($role && $role['is_default']) {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Standard-Rollen werden automatisch verwaltet und können nicht manuell geändert werden.'];
        }
        header('Location: /admin/roles');
        exit;
    }

    // Create new custom role
    if ($action === 'create_role') {
        $label = trim($_POST['label'] ?? '');
        $slug  = preg_replace('/[^a-z0-9\-]/', '-', strtolower($label));
        $slug  = trim($slug, '-');

        if (!$label || !$slug) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Rollenname ist Pflichtfeld.'];
        } elseif (DB::fetch("SELECT id FROM `{$tr}` WHERE slug = ?", [$slug])) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => "Rolle '{$slug}' existiert bereits."];
        } else {
            DB::insert($tr, ['slug' => $slug, 'label' => $label, 'is_default' => 0]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => "Rolle '{$label}' erstellt."];
        }
        header('Location: /admin/roles');
        exit;
    }

    // Delete custom role
    if ($action === 'delete_role') {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $role   = DB::fetch("SELECT * FROM `{$tr}` WHERE id = ? AND is_default = 0", [$roleId]);
        if ($role) {
            DB::delete($tr, ['id' => $roleId]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => "Rolle '{$role['label']}' gelöscht."];
        }
        header('Location: /admin/roles');
        exit;
    }
}

// Load all roles and permissions
$roles = DB::fetchAll("SELECT * FROM `{$tr}` ORDER BY is_default DESC, label ASC");
$permissions = DB::fetchAll("SELECT * FROM `{$tp}` ORDER BY slug ASC");

// Load role→permission mapping
$rolePerms = [];
foreach (DB::fetchAll(
    "SELECT rp.role_id, p.slug FROM `{$trp}` rp JOIN `{$tp}` p ON p.id = rp.permission_id"
) as $row) {
    $rolePerms[$row['role_id']][] = $row['slug'];
}

$pageTitle = 'Rollen & Rechte';
$activeNav = 'roles';

ob_start();
?>
<div class="row g-4">

    <!-- Role list -->
    <div class="col-lg-8">

        <?php foreach ($roles as $role):
            $isDefault  = (bool) $role['is_default'];
            $isForge    = $role['slug'] === 'forge';
            $assigned   = $rolePerms[$role['id']] ?? [];
        ?>
        <div class="card mb-3 <?= $isDefault ? '' : 'border-secondary' ?>">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <strong><?= htmlspecialchars($role['label']) ?></strong>
                    <code class="text-secondary small"><?= htmlspecialchars($role['slug']) ?></code>
                    <?php if ($isDefault): ?>
                    <span class="badge bg-secondary" style="font-size:.65rem">Standard</span>
                    <?php endif ?>
                    <?php if ($isForge): ?>
                    <span class="badge bg-warning text-dark" style="font-size:.65rem">Alle Rechte</span>
                    <?php endif ?>
                </div>
                <?php if (!$isDefault && !$isForge): ?>
                <form method="post" onsubmit="return confirm('Rolle löschen?')">
                    <input type="hidden" name="_csrf"    value="<?= Auth::csrfToken() ?>">
                    <input type="hidden" name="_action"  value="delete_role">
                    <input type="hidden" name="role_id"  value="<?= $role['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif ?>
            </div>
            <div class="card-body">
                <?php if ($isForge): ?>
                <p class="text-secondary small mb-0">
                    Forge hat immer alle Rechte — unabhängig von der Tabelle.
                </p>
                <?php elseif ($isDefault): ?>
                <p class="text-secondary small mb-2">
                    Standard-Rollen werden durch <code>Auth::DEFAULT_ROLE_PERMISSIONS</code> verwaltet.
                    Änderungen hier werden bei jedem Login überschrieben.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($permissions as $perm): ?>
                    <span class="badge <?= in_array($perm['slug'], $assigned, true) ? 'bg-success' : 'bg-dark border' ?>"
                          title="<?= htmlspecialchars($perm['description'] ?? '') ?>">
                        <?= htmlspecialchars($perm['slug']) ?>
                    </span>
                    <?php endforeach ?>
                </div>
                <?php else: ?>
                <form method="post">
                    <input type="hidden" name="_csrf"    value="<?= Auth::csrfToken() ?>">
                    <input type="hidden" name="_action"  value="save_role_permissions">
                    <input type="hidden" name="role_id"  value="<?= $role['id'] ?>">
                    <div class="row g-2 mb-3">
                        <?php foreach ($permissions as $perm): ?>
                        <div class="col-sm-6 col-lg-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="permissions[]"
                                       value="<?= htmlspecialchars($perm['slug']) ?>"
                                       id="perm_<?= $role['id'] ?>_<?= $perm['slug'] ?>"
                                       <?= in_array($perm['slug'], $assigned, true) ? 'checked' : '' ?>>
                                <label class="form-check-label small"
                                       for="perm_<?= $role['id'] ?>_<?= $perm['slug'] ?>"
                                       title="<?= htmlspecialchars($perm['description'] ?? '') ?>">
                                    <?= htmlspecialchars($perm['label'] ?? $perm['slug']) ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach ?>
                    </div>
                    <button class="btn btn-sm btn-primary">
                        <i class="bi bi-floppy"></i> Rechte speichern
                    </button>
                </form>
                <?php endif ?>
            </div>
        </div>
        <?php endforeach ?>

    </div>

    <!-- Create custom role -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header py-2"><small class="text-secondary">Eigene Rolle erstellen</small></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
                    <input type="hidden" name="_action" value="create_role">
                    <div class="mb-3">
                        <label class="form-label">Rollenname</label>
                        <input type="text" name="label" class="form-control"
                               placeholder="z.B. Moderator" required>
                        <div class="form-text">Slug wird automatisch generiert</div>
                    </div>
                    <button class="btn btn-primary w-100">
                        <i class="bi bi-plus-lg"></i> Rolle erstellen
                    </button>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header py-2"><small class="text-secondary">Hinweis</small></div>
            <div class="card-body small text-secondary">
                <p><strong class="text-white">Standard-Rollen</strong> (member, author, editor, admin)
                werden durch den CMS-Code verwaltet und bei jedem Login synchronisiert.</p>
                <p class="mb-0"><strong class="text-white">Eigene Rollen</strong> können frei konfiguriert
                werden. Benutzer werden im Benutzer-Menü einer Rolle zugewiesen.</p>
            </div>
        </div>
    </div>

</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
