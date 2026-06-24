<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;
use Esse\DB;
use Esse\Flash;
use Esse\GitHubApi;

if (!Auth::meetsRole('forge') && !Auth::can('manage_settings')) {
    http_response_code(403); echo '403 Forbidden'; exit;
}

require_once __DIR__ . '/package-install.php';

$ts  = DB::table('settings');
$tr  = DB::table('repo_channels');
$tab = $_GET['tab'] ?? 'installed';

$flash = Flash::consume();

// Discover installed icon packs
function discoverIconPacks(): array
{
    $packs = [];
    foreach (glob(ESSE_ROOT . '/public/vendor/*/iconpack.json') ?: [] as $jsonFile) {
        $meta = json_decode(file_get_contents($jsonFile), true);
        if (!empty($meta['name'])) {
            $dir = basename(dirname($jsonFile));
            $meta['dir']     = $dir;
            $meta['css_url'] = '/public/vendor/' . $dir . '/' . ($meta['css'] ?? '');
            $packs[$meta['name']] = $meta;
        }
    }
    return $packs;
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $action = $_POST['_action'] ?? '';

    // Activate pack
    if ($action === 'activate') {
        $name = $_POST['pack_name'] ?? '';
        $packs = discoverIconPacks();
        if (isset($packs[$name])) {
            DB::query(
                "INSERT INTO `{$ts}` (`key`, `value`) VALUES ('icon_pack', ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$name]
            );
            Flash::set('success', "Icon-Pack '{$name}' aktiviert.");
        }
        header('Location: /admin/iconpacks');
        exit;
    }

    // Install from repo channel
    if ($action === 'install_from_repo') {
        $fullName = trim($_POST['repo_full_name'] ?? '');
        if ($fullName && preg_match('#^[a-zA-Z0-9\-]+/[a-zA-Z0-9\-\.]+$#', $fullName)) {
            // manage_repos (Kanalverwaltung) ist getrennt von der hier benoetigten Berechtigung —
            // ohne diese Pruefung koennte jedes beliebige GitHub-Repo installiert werden, auch
            // ausserhalb der konfigurierten Kanaele.
            if (!packageRepoChannelAllowed($fullName)) {
                AuditLog::record('iconpack_install_failed', Auth::id(), Auth::user()['email'] ?? null, ['source' => 'repo', 'repo' => $fullName, 'reason' => 'channel_not_allowed']);
                Flash::set('danger', 'Dieser Kanal ist nicht konfiguriert oder deaktiviert.');
                header('Location: /admin/iconpacks?tab=available');
                exit;
            }
            $release = GitHubApi::latestRelease($fullName);
            if ($release && $release['download_url']) {
                $tmpFile = ESSE_PRIVATE_PATH . '/storage/update_tmp/iconpack_' . uniqid() . '.zip';
                $dir = dirname($tmpFile);
                if (!is_dir($dir)) mkdir($dir, 0750, true);
                $ch = curl_init($release['download_url']);
                curl_setopt_array($ch, [
                    \CURLOPT_RETURNTRANSFER => true,
                    \CURLOPT_FOLLOWLOCATION => true,
                    \CURLOPT_TIMEOUT        => 30,
                    \CURLOPT_USERAGENT      => 'ESSE-CMS/' . \ESSE_VERSION,
                    \CURLOPT_FAILONERROR    => true,
                    \CURLOPT_MAXFILESIZE    => 50 * 1024 * 1024,
                ]);
                $data = curl_exec($ch);
                $code = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
                if ($data === false || $code < 200 || $code >= 300 || strlen($data) < 100 || substr($data, 0, 2) !== 'PK') {
                    AuditLog::record('iconpack_install_failed', Auth::id(), Auth::user()['email'] ?? null, ['source' => 'repo', 'repo' => $fullName, 'reason' => 'download_failed']);
                    Flash::set('danger', 'Download fehlgeschlagen oder ungültige ZIP-Datei.');
                    header('Location: /admin/iconpacks?tab=available'); exit;
                }
                file_put_contents($tmpFile, $data);
                $result = packageInstallZip($tmpFile, 'iconpack');
                @unlink($tmpFile);
                if (is_string($result)) {
                    AuditLog::record('iconpack_install_failed', Auth::id(), Auth::user()['email'] ?? null, ['source' => 'repo', 'repo' => $fullName, 'reason' => $result]);
                    Flash::set('danger', $result);
                } else {
                    AuditLog::record('iconpack_installed', Auth::id(), Auth::user()['email'] ?? null, ['pack' => $result['name'], 'version' => $result['version'] ?? null, 'source' => 'repo']);
                    Flash::set('success', "Icon-Pack '{$result['name']}' v{$result['version']} installiert.");
                }
            } else {
                AuditLog::record('iconpack_install_failed', Auth::id(), Auth::user()['email'] ?? null, ['source' => 'repo', 'repo' => $fullName, 'reason' => 'no_release']);
                Flash::set('danger', 'Kein Release gefunden.');
            }
        }
        header('Location: /admin/iconpacks?tab=available');
        exit;
    }

    // Upload ZIP — landet unter /public/vendor/ (öffentlich per HTTP erreichbar) und ist damit
    // mindestens so riskant wie Plugin-/Theme-Uploads, daher ebenfalls auf Forge beschränkt.
    if ($action === 'upload' && !empty($_FILES['pack_zip']['tmp_name'])) {
        if (!Auth::meetsRole('forge')) {
            AuditLog::record('iconpack_install_failed', Auth::id(), Auth::user()['email'] ?? null, ['reason' => 'forbidden_role']);
            http_response_code(403); echo '403 Forbidden'; exit;
        }
        $result = packageInstallZip($_FILES['pack_zip']['tmp_name'], 'iconpack');
        if (is_string($result)) {
            AuditLog::record('iconpack_install_failed', Auth::id(), Auth::user()['email'] ?? null, ['reason' => $result]);
            Flash::set('danger', $result);
        } else {
            AuditLog::record('iconpack_installed', Auth::id(), Auth::user()['email'] ?? null, ['pack' => $result['name'], 'version' => $result['version'] ?? null]);
            Flash::set('success', "Icon-Pack '{$result['name']}' v{$result['version']} installiert.");
        }
        header('Location: /admin/iconpacks');
        exit;
    }

    // Delete pack
    if ($action === 'delete') {
        $name = $_POST['pack_name'] ?? '';
        $packs = discoverIconPacks();
        $active = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'icon_pack'") ?? '';

        if ($name === $active) {
            Flash::set('danger', 'Das aktive Icon-Pack kann nicht gelöscht werden.');
        } elseif (isset($packs[$name]) && $packs[$name]['dir'] !== 'bootstrap-icons') {
            $dirToRemove = ESSE_ROOT . '/public/vendor/' . $packs[$name]['dir'];
            packageDeleteDir($dirToRemove);
            if (is_dir($dirToRemove)) {
                AuditLog::record('iconpack_delete_failed', Auth::id(), Auth::user()['email'] ?? null, ['pack' => $name]);
                Flash::set('danger', "Icon-Pack '{$name}' konnte nicht vollständig entfernt werden.");
            } else {
                AuditLog::record('iconpack_deleted', Auth::id(), Auth::user()['email'] ?? null, ['pack' => $name]);
                Flash::set('success', "Icon-Pack '{$name}' gelöscht.");
            }
        } else {
            Flash::set('danger', 'Dieses Pack kann nicht gelöscht werden.');
        }
        header('Location: /admin/iconpacks');
        exit;
    }

    // Cache leeren
    if ($action === 'refresh_cache') {
        @unlink(ESSE_PRIVATE_PATH . '/storage/cache/iconpack_repos.json');
        Flash::set('success', 'Cache geleert.');
        header('Location: /admin/iconpacks?tab=available');
        exit;
    }

    // Unbekannte/nicht mehr unterstützte Aktion (z.B. das frühere add_repo/remove_repo, das
    // jetzt zentral unter /admin/repos läuft) — klar als 403 ablehnen statt mit 200 auf die
    // normale Seite zu antworten, das wäre für einen POST-Endpoint ein irreführendes Signal.
    if (in_array($action, ['add_repo', 'remove_repo', 'toggle_trust'], true)) {
        AuditLog::record('repo_action_forbidden', Auth::id(), Auth::user()['email'] ?? null, ['action' => $action, 'context' => 'legacy_endpoint']);
    }
    http_response_code(403); echo '403 Forbidden'; exit;
}

$packs  = discoverIconPacks();
$active = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'icon_pack'") ?? 'bootstrap-icons';

$installedByName = [];
foreach ($packs as $n => $p) { if (!empty($p['version'])) $installedByName[$n] = $p['version']; }

$pageTitle = 'Icon-Packs';
$activeNav = 'iconpacks';

ob_start();
?>
<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'installed' ? 'active' : '' ?>" href="/admin/iconpacks?tab=installed">
            <i class="bi bi-emoji-smile me-1"></i>Installiert
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'available' ? 'active' : '' ?>" href="/admin/iconpacks?tab=available">
            <i class="bi bi-cloud-download me-1"></i>Verfügbar
        </a>
    </li>
</ul>

<?php if ($tab === 'available'): ?>
<?php
$repos = DB::fetchAll("SELECT * FROM `{$tr}` ORDER BY trusted DESC, label ASC");

$cacheFile = ESSE_PRIVATE_PATH . '/storage/cache/iconpack_repos.json';
$available = null;
if (!is_dir(dirname($cacheFile))) {
    @mkdir(dirname($cacheFile), 0750, true);
}
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
    $available = json_decode(file_get_contents($cacheFile), true);
}
if (!$available) {
    $available = [];
    foreach ($repos as $repo) {
        if (!$repo['active']) continue;
        $results = GitHubApi::searchIconPacks($repo['owner'], (bool) $repo['trusted']);
        foreach ($results as $r) {
            $r['channel_label']   = $repo['label'];
            $r['channel_trusted'] = (bool) $repo['trusted'];
            $rel = GitHubApi::latestRelease($r['full_name']);
            $r['latest_version'] = $rel['version'] ?? null;
            $r['download_url']   = $rel['download_url'] ?? null;
            $available[] = $r;
        }
    }
    @file_put_contents($cacheFile, json_encode($available));
}
?>
<?php if ($available): ?>
<div class="row g-3 mb-4">
<?php foreach ($available as $r):
    $instVer   = $installedByName[$r['name']] ?? null;
    $latestVer = $r['latest_version'] ?? null;
    $hasUpdate = $instVer && $latestVer && version_compare(ltrim($latestVer,'v'), ltrim($instVer,'v'), '>');
    $isInstalled = $instVer !== null;
?>
<div class="col-lg-6">
    <div class="card h-100 <?= $hasUpdate ? 'border-warning' : ($isInstalled ? 'border-success' : '') ?>">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <strong><?= htmlspecialchars($r['name']) ?></strong>
                <?php if ($r['channel_trusted']): ?>
                <span class="badge bg-success badge-xs"><i class="bi bi-shield-check"></i> Offiziell</span>
                <?php else: ?>
                <span class="badge bg-warning text-dark badge-xs"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($r['channel_label']) ?></span>
                <?php endif ?>
            </div>
            <?php if ($hasUpdate): ?>
            <span class="badge bg-warning text-dark"><i class="bi bi-arrow-up-circle"></i> v<?= htmlspecialchars($latestVer) ?></span>
            <?php elseif ($isInstalled): ?>
            <span class="badge bg-success">v<?= htmlspecialchars($instVer) ?> Aktuell</span>
            <?php endif ?>
        </div>
        <div class="card-body">
            <?php if ($r['description']): ?><p class="text-secondary small mb-3"><?= htmlspecialchars($r['description']) ?></p><?php endif ?>
            <div class="d-flex gap-2">
                <form method="post" action="/admin/iconpacks"
                      <?= !$r['channel_trusted'] ? 'data-confirm="Achtung: Nicht-offizieller Kanal. Nur installieren wenn du der Quelle vertraust!"' : '' ?>>
                    <input type="hidden" name="_csrf"           value="<?= Auth::csrfToken() ?>">
                    <input type="hidden" name="_action"         value="install_from_repo">
                    <input type="hidden" name="repo_full_name"  value="<?= htmlspecialchars($r['full_name']) ?>">
                    <?php if (!$isInstalled): ?>
                    <button class="btn btn-sm btn-primary"><i class="bi bi-download"></i> Installieren<?= $latestVer ? " (v{$latestVer})" : '' ?></button>
                    <?php elseif ($hasUpdate): ?>
                    <button class="btn btn-sm btn-warning text-dark"><i class="bi bi-arrow-up-circle"></i> Update auf v<?= htmlspecialchars($latestVer) ?></button>
                    <?php else: ?>
                    <button class="btn btn-sm btn-outline-secondary" disabled><i class="bi bi-check"></i> Aktuell</button>
                    <?php endif ?>
                </form>
                <a href="<?= htmlspecialchars($r['html_url']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-github"></i></a>
            </div>
        </div>
    </div>
</div>
<?php endforeach ?>
</div>
<?php else: ?>
<div class="alert alert-secondary">Keine Icon-Packs gefunden. Repos müssen das Topic <code>esse-iconpack</code> auf GitHub haben.</div>
<?php endif ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <small class="text-secondary">
        Durchsucht <?= count($repos) ?> Kanal<?= count($repos) === 1 ? '' : 'e' ?> nach dem Topic <code>esse-iconpack</code>.
    </small>
    <div class="d-flex gap-2">
        <form method="post" action="/admin/iconpacks?tab=available" class="d-inline">
            <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="_action" value="refresh_cache">
            <button class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-clockwise"></i> Cache leeren
            </button>
        </form>
        <?php if (Auth::meetsRole('forge') || Auth::can('manage_repos')): ?>
        <a href="/admin/repos" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-diagram-3"></i> Kanäle verwalten
        </a>
        <?php endif ?>
    </div>
</div>

<?php else: ?>
<!-- ── INSTALLIERT-TAB ── -->
<div class="row g-4">
    <div class="col-lg-8">

        <?php if ($packs): ?>
        <div class="row g-3 mb-4">
        <?php foreach ($packs as $name => $pack):
            $isActive = $name === $active;
        ?>
        <div class="col-md-6">
            <div class="card h-100 <?= $isActive ? 'border-primary' : '' ?>">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <strong><?= htmlspecialchars($pack['name']) ?></strong>
                        <small class="text-secondary">v<?= htmlspecialchars($pack['version'] ?? '—') ?></small>
                    </div>
                    <?php if ($isActive): ?>
                    <span class="badge bg-primary">Aktiv</span>
                    <?php endif ?>
                </div>
                <div class="card-body">
                    <p class="text-secondary small mb-2"><?= htmlspecialchars($pack['description'] ?? '') ?></p>
                    <div class="mb-3">
                        <code class="small text-secondary">Prefix: <?= htmlspecialchars($pack['prefix'] ?? '') ?></code>
                    </div>
                    <!-- Preview: first few icons using the pack's CSS -->
                    <link rel="stylesheet" href="<?= htmlspecialchars($pack['css_url']) ?>">
                    <div class="d-flex gap-2 mb-3 admin-icon-sample-row">
                        <i class="<?= htmlspecialchars($pack['prefix'] ?? '') ?>house"></i>
                        <i class="<?= htmlspecialchars($pack['prefix'] ?? '') ?>image"></i>
                        <i class="<?= htmlspecialchars($pack['prefix'] ?? '') ?>gear"></i>
                        <i class="<?= htmlspecialchars($pack['prefix'] ?? '') ?>person"></i>
                        <i class="<?= htmlspecialchars($pack['prefix'] ?? '') ?>download"></i>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if (!$isActive): ?>
                        <form method="post">
                            <input type="hidden" name="_csrf"      value="<?= Auth::csrfToken() ?>">
                            <input type="hidden" name="_action"    value="activate">
                            <input type="hidden" name="pack_name"  value="<?= htmlspecialchars($name) ?>">
                            <button class="btn btn-sm btn-outline-primary">Aktivieren</button>
                        </form>
                        <?php endif ?>
                        <?php if (!$isActive && $pack['dir'] !== 'bootstrap-icons'): ?>
                        <form method="post" data-confirm="Icon-Pack löschen?">
                            <input type="hidden" name="_csrf"      value="<?= Auth::csrfToken() ?>">
                            <input type="hidden" name="_action"    value="delete">
                            <input type="hidden" name="pack_name"  value="<?= htmlspecialchars($name) ?>">
                            <button class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        <?php endif ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach ?>
        </div>
        <?php else: ?>
        <div class="alert alert-secondary">Keine Icon-Packs gefunden.</div>
        <?php endif ?>

    </div>

    <div class="col-lg-4">
        <?php if (Auth::meetsRole('forge')): ?>
        <div class="card mb-3">
            <div class="card-header py-2"><small class="text-secondary">Icon-Pack installieren</small></div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
                    <input type="hidden" name="_action" value="upload">
                    <div class="mb-3">
                        <input type="file" name="pack_zip" class="form-control" accept=".zip" required>
                        <div class="form-text">ZIP mit <code>iconpack.json</code>, CSS und Fonts</div>
                    </div>
                    <button class="btn btn-primary w-100">
                        <i class="bi bi-upload"></i> Installieren
                    </button>
                </form>
            </div>
        </div>
        <?php endif ?>

        <div class="card">
            <div class="card-header py-2"><small class="text-secondary">iconpack.json Format</small></div>
            <div class="card-body">
                <pre class="small mb-0 admin-code-preview">{
  "name": "mein-pack",
  "version": "1.0.0",
  "description": "...",
  "prefix": "ph ph-",
  "css": "phosphor.css"
}</pre>
            </div>
        </div>
    </div>
</div>
<?php endif ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
