<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;
use Esse\DB;
use Esse\Flash;
use Esse\Mailer;

$tu = DB::table('users');

// ── POST-Handler (AJAX) ─────────────────────────────────────────────────────
// Mirrors admin/pages/list.php's Klick-Badge -> Modal -> AJAX-Muster.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    if (($_POST['_action'] ?? '') === 'approve_user') {
        header('Content-Type: application/json');
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $target   = DB::fetch("SELECT * FROM `{$tu}` WHERE id = ?", [$targetId]);
        if (!$target) { echo json_encode(['error' => 'not_found']); exit; }

        DB::update($tu, ['approved_at' => date('Y-m-d H:i:s')], ['id' => $targetId]);
        AuditLog::record('user_approved', Auth::id(), Auth::user()['email'] ?? null, [
            'target_user_id' => $targetId, 'target_email' => $target['email'],
        ]);

        $ts       = DB::table('settings');
        $siteUrl  = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'site_url'") ?? '';
        $siteName = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'site_name'") ?? 'ESSE CMS';
        try {
            Mailer::send(
                $target['email'], $target['display_name'],
                'Account freigeschaltet — ' . $siteName,
                "<p>Hallo {$target['display_name']},</p>"
                . "<p>dein Account wurde von einem Administrator freigeschaltet. Du kannst dich jetzt einloggen.</p>"
                . "<p><a href=\"" . rtrim($siteUrl, '/') . "/login\">Jetzt einloggen</a></p>"
                . "<p>— {$siteName}</p>"
            );
        } catch (\Throwable $e) {
            error_log('ESSE Mailer error: ' . $e->getMessage());
        }

        echo json_encode(['success' => true]); exit;
    }

    http_response_code(403); exit;
}

$users = DB::fetchAll(
    "SELECT id, display_name, email, role, active, approved_at, created_at
       FROM `{$tu}`
      ORDER BY FIELD(role,'forge','admin','editor','author','member'), display_name ASC"
);

$flash = Flash::consume();

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
                    <?php if ($user['approved_at'] === null): ?>
                        <span class="badge bg-warning text-dark admin-inline-action" data-approve-user="<?= $user['id'] ?>"
                              data-name="<?= htmlspecialchars($user['display_name']) ?>">
                            Wartet auf Freigabe
                        </span>
                    <?php elseif ($user['active']): ?>
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

<div class="modal fade" id="approveModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content bg-dark border-secondary">
      <div class="modal-header border-secondary">
        <h5 class="modal-title">Account freigeben: <span id="am-name" class="text-white"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="am-user-id">
        <p class="text-secondary small mb-0">Der Account wird sofort einloggbar und der Nutzer per E-Mail informiert.</p>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
        <button type="button" id="am-save" class="btn btn-success">Freigeben</button>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();

$extraScriptConfig = array_merge($extraScriptConfig ?? [], ['admin-users-list-config' => [
    'csrf' => Auth::csrfToken(),
]]);
$extraScriptFiles = array_merge($extraScriptFiles ?? [], ['/public/assets/js/admin-users-list.js']);

require dirname(__DIR__) . '/layout.php';
