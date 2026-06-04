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
    'forge'  => ['7c3aed', 'Forge'],
    'admin'  => ['0d6efd', 'Admin'],
    'editor' => ['0891b2', 'Editor'],
    'author' => ['059669', 'Author'],
    'member' => ['374151', 'Member'],
];

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
            <?php [$color, $label] = $roleLabels[$user['role']] ?? ['6c757d', $user['role']]; ?>
            <tr class="align-middle <?= !$user['active'] ? 'opacity-50' : '' ?>">
                <td class="fw-semibold">
                    <?= htmlspecialchars($user['display_name']) ?>
                    <?php if ($user['id'] === Auth::id()): ?>
                        <span class="badge bg-secondary ms-1" style="font-size:.65rem">Du</span>
                    <?php endif ?>
                </td>
                <td class="text-secondary"><?= htmlspecialchars($user['email']) ?></td>
                <td>
                    <span class="badge rounded-pill" style="background:#<?= $color ?>">
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
                    // Forge kann nur von Forge bearbeitet werden
                    $canEdit = Auth::can('manage_users') && ($user['role'] !== 'forge' || Auth::role() === 'forge');
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
