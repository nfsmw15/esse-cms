<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;
use Esse\DB;
use Esse\Flash;

// Repo-Kanäle definieren eine Vertrauensgrenze (woher Plugin-/Theme-/Icon-Pack-Installs
// kommen dürfen) — dafür reicht manage_plugins/manage_themes nicht, das ist eine eigene,
// engere Berechtigung.
if (!Auth::meetsRole('forge') && !Auth::can('manage_repos')) {
    http_response_code(403); echo '403 Forbidden'; exit;
}

$tr    = DB::table('repo_channels');
$flash = Flash::consume();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $action = $_POST['_action'] ?? '';

    if ($action === 'add_repo') {
        $owner = trim(preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['repo_owner'] ?? ''));
        $label = trim($_POST['repo_label'] ?? $owner);
        if ($owner) {
            DB::query(
                "INSERT IGNORE INTO `{$tr}` (owner, label, trusted) VALUES (?, ?, 0)",
                [$owner, $label ?: $owner]
            );
            AuditLog::record('repo_added', Auth::id(), Auth::user()['email'] ?? null, ['owner' => $owner, 'label' => $label ?: $owner]);
            Flash::set('warning', "Kanal '{$owner}' hinzugefügt. Nicht verifizierter Kanal — nur vertrauenswürdige Quellen installieren.");
        }
        header('Location: /admin/repos');
        exit;
    }

    if ($action === 'remove_repo') {
        $repoId = (int) ($_POST['repo_id'] ?? 0);
        $repo   = DB::fetch("SELECT * FROM `{$tr}` WHERE id = ?", [$repoId]);
        if ($repo && !$repo['trusted']) {
            DB::delete($tr, ['id' => $repoId]);
            AuditLog::record('repo_removed', Auth::id(), Auth::user()['email'] ?? null, ['owner' => $repo['owner']]);
            Flash::set('success', "Kanal '{$repo['owner']}' entfernt.");
        }
        header('Location: /admin/repos');
        exit;
    }

    // Vertrauensstufe ändern — bewusst nur Forge, nicht manage_repos: ein Kanal, den ein
    // Admin selbst hinzugefügt hat, darf er nicht auch selbst als "vertrauenswürdig" markieren.
    if ($action === 'toggle_trust') {
        if (!Auth::meetsRole('forge')) {
            AuditLog::record('repo_action_forbidden', Auth::id(), Auth::user()['email'] ?? null, ['action' => $action]);
            http_response_code(403); echo '403 Forbidden'; exit;
        }
        $repoId = (int) ($_POST['repo_id'] ?? 0);
        $repo   = DB::fetch("SELECT * FROM `{$tr}` WHERE id = ?", [$repoId]);
        if ($repo) {
            $newTrusted = $repo['trusted'] ? 0 : 1;
            DB::update($tr, ['trusted' => $newTrusted], ['id' => $repoId]);
            AuditLog::record('repo_trust_changed', Auth::id(), Auth::user()['email'] ?? null, [
                'owner' => $repo['owner'], 'old' => (bool) $repo['trusted'], 'new' => (bool) $newTrusted,
            ]);
            Flash::set('success', "Vertrauensstufe für '{$repo['owner']}' geändert.");
        }
        header('Location: /admin/repos');
        exit;
    }

    // Caches aller drei Pakettypen leeren — ein Kanal kann ja alle drei anbieten.
    if ($action === 'refresh_cache') {
        foreach (['plugin_repos.json', 'theme_repos.json', 'iconpack_repos.json'] as $file) {
            @unlink(ESSE_PRIVATE_PATH . '/storage/cache/' . $file);
        }
        AuditLog::record('repo_cache_refreshed', Auth::id(), Auth::user()['email'] ?? null);
        Flash::set('success', 'Caches geleert.');
        header('Location: /admin/repos');
        exit;
    }
}

$repos = DB::fetchAll("SELECT * FROM `{$tr}` ORDER BY trusted DESC, label ASC");

$pageTitle = 'Repo-Kanäle';
$activeNav = 'repos';

ob_start();
?>
<p class="text-secondary">
    Kanäle sind vertrauenswürdige GitHub-Accounts, die nach Plugins, Themes und Icon-Packs durchsucht
    werden (Topics <code>esse-plugin</code>, <code>esse-theme</code>, <code>esse-iconpack</code>).
    Ein Kanal kann alle drei Pakettypen anbieten, muss es aber nicht — das entscheidet sich allein
    über die Topic-Tags auf den jeweiligen Repos.
</p>

<div class="card">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <small class="text-secondary">Kanäle</small>
        <form method="post" class="d-inline">
            <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="_action" value="refresh_cache">
            <button class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-clockwise"></i> Caches leeren
            </button>
        </form>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
        <?php foreach ($repos as $repo): ?>
        <tr class="align-middle">
            <td class="ps-3">
                <strong><?= htmlspecialchars($repo['owner']) ?></strong>
                <small class="text-secondary ms-1"><?= htmlspecialchars($repo['label']) ?></small>
            </td>
            <td>
                <?php if ($repo['trusted']): ?>
                <span class="badge bg-success"><i class="bi bi-shield-check"></i> Offiziell/vertrauenswürdig</span>
                <?php else: ?>
                <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> Nicht verifiziert</span>
                <?php endif ?>
            </td>
            <td class="text-end pe-3">
                <?php if (Auth::meetsRole('forge')): ?>
                <form method="post" class="d-inline" data-confirm="Vertrauensstufe für '<?= htmlspecialchars($repo['owner']) ?>' wirklich ändern?">
                    <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
                    <input type="hidden" name="_action" value="toggle_trust">
                    <input type="hidden" name="repo_id" value="<?= $repo['id'] ?>">
                    <button class="btn btn-sm btn-outline-secondary" title="Vertrauensstufe ändern">
                        <i class="bi bi-shield"></i>
                    </button>
                </form>
                <?php endif ?>
                <?php if (!$repo['trusted']): ?>
                <form method="post" class="d-inline" data-confirm="Kanal entfernen?">
                    <input type="hidden" name="_csrf"    value="<?= Auth::csrfToken() ?>">
                    <input type="hidden" name="_action"  value="remove_repo">
                    <input type="hidden" name="repo_id"  value="<?= $repo['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif ?>
            </td>
        </tr>
        <?php endforeach ?>
        <?php if (!$repos): ?>
        <tr><td class="text-secondary text-center py-4" colspan="3">Noch keine Kanäle.</td></tr>
        <?php endif ?>
        </table>
    </div>
    <div class="card-footer">
        <form method="post" class="d-flex gap-2 align-items-end">
            <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="_action" value="add_repo">
            <div>
                <label class="form-label small">GitHub-Benutzername</label>
                <input type="text" name="repo_owner" class="form-control form-control-sm font-monospace"
                       placeholder="username" required>
            </div>
            <div>
                <label class="form-label small">Label (optional)</label>
                <input type="text" name="repo_label" class="form-control form-control-sm"
                       placeholder="Community">
            </div>
            <button class="btn btn-sm btn-outline-warning">
                <i class="bi bi-plus-lg"></i> Kanal hinzufügen
            </button>
        </form>
        <div class="form-text mt-1">
            <i class="bi bi-exclamation-triangle text-warning"></i>
            Nur vertrauenswürdige Quellen hinzufügen. Plugins und Themes können PHP-Code auf dem Server ausführen.
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
