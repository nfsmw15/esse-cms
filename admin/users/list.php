<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;

$tu = DB::table('users');

$users = DB::fetchAll(
    "SELECT id, display_name, email, role, active, created_at
       FROM `{$tu}`
      ORDER BY FIELD(role,'forge','admin','editor','author','member'), display_name ASC"
);

$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

$roleLabels = [
    'forge'  => ['badge-forge', 'Forge'],
    'admin'  => ['badge-admin', 'Admin'],
    'editor' => ['badge-editor', 'Editor'],
    'author' => ['badge-author', 'Author'],
    'member' => ['badge-member', 'Member'],
];
$manageableRoles = ['editor', 'author', 'member'];
if (Auth::can('manage_admins')) {
    $manageableRoles[] = 'admin';

    $tr = DB::table('roles');
    $customRoles = DB::fetchAll("SELECT slug FROM `{$tr}` WHERE is_default = 0");
    foreach ($customRoles as $customRole) {
        $manageableRoles[] = (string)$customRole['slug'];
    }
}
if (Auth::role() === 'forge') $manageableRoles[] = 'forge';

$pageTitle = 'Benutzer';
$activeNav = 'users';

ob_start();
?>
<div class="d-flex justify-content-end mb-3">
    <?php if (Auth::can('manage_users')): ?>
    <a href="/admin/users/create" class="btn btn-primary btn-sm">
        <i class="bi bi-person-plus"></i> Neuer Benutzer
    </a>
    <?php endif ?>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr>
                <th>Name</th>
                <th>E-Mail</th>
                <th>Rolle</th>
                <th>Status</th>
                <th>Erstellt</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($users as $user): ?>
            <?php [$roleClass, $label] = $roleLabels[$user['role']] ?? ['bg-secondary', $user['role']]; ?>
            <tr class="align-middle <?= !$user['active'] ? 'opacity-50' : '' ?>">
                <td class="fw-semibold">
                    <?= htmlspecialchars($user['display_name']) ?>
                    <?php if ($user['id'] === Auth::id()): ?>
                        <span class="badge bg-secondary ms-1 badge-xs">Du</span>
                    <?php endif ?>
                </td>
                <td class="text-secondary"><?= htmlspecialchars($user['email']) ?></td>
                <td>
                    <span class="badge rounded-pill <?= htmlspecialchars($roleClass) ?>">
                        <?= $label ?>
                    </span>
                </td>
                <td>
                    <?php if ($user['active']): ?>
                        <span class="badge bg-success">Aktiv</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Inaktiv</span>
                    <?php endif ?>
                </td>
                <td class="text-secondary small">
                    <?= date('d.m.Y', strtotime($user['created_at'])) ?>
                </td>
                <td class="text-end">
                    <?php
                    $canEdit = Auth::can('manage_users') && in_array($user['role'], $manageableRoles, true);
                    ?>
                    <?php if ($canEdit): ?>
                    <a href="/admin/users/edit/<?= $user['id'] ?>"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <?php endif ?>
                </td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
