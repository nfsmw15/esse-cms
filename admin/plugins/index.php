<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;
use Esse\DB;
use Esse\Flash;
use Esse\GitHubApi;

// Plugin installation = PHP code execution — require explicit permission or Forge role
if (!Auth::meetsRole('forge') && !Auth::can('manage_plugins')) {
    http_response_code(403); echo '403 Forbidden'; exit;
}

require_once dirname(__DIR__) . '/package-install.php';

$ts  = DB::table('settings');
$tr  = DB::table('repo_channels');
$tab = $_GET['tab'] ?? 'installed';

// Load enabled plugins list
$enabledRaw = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'enabled_plugins'") ?? '[]';
$enabled    = json_decode($enabledRaw, true) ?: [];

// Discover installed plugins
$plugins = [];
foreach (glob(ESSE_ROOT . '/plugins/*/plugin.json') ?: [] as $jsonFile) {
    $meta = json_decode(file_get_contents($jsonFile), true);
    if (empty($meta['name'])) continue;
    $slug            = basename(dirname($jsonFile));
    $meta['slug']    = $slug;
    $meta['enabled'] = in_array($slug, $enabled, true);
    $plugins[$slug]  = $meta;
}

$flash = Flash::consume();

// -- POST actions --
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $action = $_POST['_action'] ?? '';
    $slug   = basename($_POST['plugin_slug'] ?? '');

    // Enable / Disable
    if (in_array($action, ['enable', 'disable'], true) && $slug) {
        if ($action === 'enable' && !in_array($slug, $enabled, true)) {
            // Check CMS version compatibility
            $meta    = $plugins[$slug] ?? [];
            $require = $meta['requires']['esse'] ?? null;
            $error   = null;
            if ($require) {
                // Parse constraint: >=1.0.0 or ^1.0.0 or just 1.0.0
                if (preg_match('/^(>=|>|<=|<|=|~|\^)?\s*([\d.]+.*)$/', trim($require), $m)) {
                    $op  = $m[1] ?: '>=';
                    $ver = preg_replace('/[^0-9.].*$/', '', $m[2]); // strip -alpha etc.
                    $cms = preg_replace('/[^0-9.].*$/', '', \ESSE_VERSION);
                    if (!version_compare($cms, $ver, $op === '^' || $op === '~' ? '>=' : $op)) {
                        $error = "Plugin benötigt ESSE CMS {$require} — installiert ist " . \ESSE_VERSION . '.';
                    }
                }
            }
            if ($error) {
                Flash::set('danger', $error);
                header('Location: /admin/plugins');
                exit;
            }
            $enabled[] = $slug;
        } elseif ($action === 'disable') {
            $enabled = array_values(array_filter($enabled, fn($s) => $s !== $slug));
        }
        saveEnabled($ts, $enabled);
        AuditLog::record(
            $action === 'enable' ? 'plugin_enabled' : 'plugin_disabled',
            Auth::id(),
            Auth::user()['email'] ?? null,
            ['plugin' => $slug]
        );
        Flash::set('success', "Plugin '{$slug}' " . ($action === 'enable' ? 'aktiviert' : 'deaktiviert') . '.');
        header('Location: /admin/plugins');
        exit;
    }

    // Uninstall
    if ($action === 'uninstall' && $slug && isset($plugins[$slug])) {
        // Call plugin uninstall() if possible
        $pluginFile = ESSE_ROOT . '/plugins/' . $slug . '/Plugin.php';
        if (file_exists($pluginFile)) {
            require_once $pluginFile;
            $class = $plugins[$slug]['class'] ?? null;
            if ($class && class_exists($class)) {
                try { (new $class())->uninstall(); } catch (\Throwable $e) {}
            }
        }
        $pluginDir = ESSE_ROOT . '/plugins/' . $slug;
        packageDeleteDir($pluginDir);
        $enabled = array_values(array_filter($enabled, fn($s) => $s !== $slug));
        saveEnabled($ts, $enabled);
        if (is_dir($pluginDir)) {
            AuditLog::record('plugin_uninstall_failed', Auth::id(), Auth::user()['email'] ?? null, ['plugin' => $slug]);
            Flash::set('danger', "Plugin '{$slug}' konnte nicht vollständig entfernt werden.");
        } else {
            AuditLog::record('plugin_uninstalled', Auth::id(), Auth::user()['email'] ?? null, ['plugin' => $slug]);
            Flash::set('success', "Plugin '{$slug}' deinstalliert.");
        }
        header('Location: /admin/plugins');
        exit;
    }

    // Install from repo (download latest release)
    if ($action === 'install_from_repo') {
        $fullName = trim($_POST['repo_full_name'] ?? '');
        if ($fullName && preg_match('#^[a-zA-Z0-9\-]+/[a-zA-Z0-9\-\.]+$#', $fullName)) {
            $release = GitHubApi::latestRelease($fullName);
            if ($release && $release['download_url']) {
                $tmpFile = ESSE_PRIVATE_PATH . '/storage/update_tmp/plugin_' . uniqid() . '.zip';
                $dir = dirname($tmpFile);
                if (!is_dir($dir)) mkdir($dir, 0750, true);

                $ch = curl_init($release['download_url']);
                curl_setopt_array($ch, [
                    \CURLOPT_RETURNTRANSFER => true,
                    \CURLOPT_FOLLOWLOCATION => true,
                    \CURLOPT_TIMEOUT        => 30,
                    \CURLOPT_USERAGENT      => 'ESSE-CMS/' . \ESSE_VERSION,
                    \CURLOPT_FAILONERROR    => true,
                    \CURLOPT_MAXFILESIZE    => 50 * 1024 * 1024, // gleiche Grenze wie packageInstallZip()
                ]);
                $data = curl_exec($ch);
                $code = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);

                if ($data === false || $code < 200 || $code >= 300
                    || strlen($data) < 100 || substr($data, 0, 2) !== 'PK') {
                    AuditLog::record('plugin_install_failed', Auth::id(), Auth::user()['email'] ?? null, ['source' => 'repo', 'repo' => $fullName, 'reason' => 'download_failed']);
                    Flash::set('danger', 'Download fehlgeschlagen oder ungültige ZIP-Datei.');
                    header('Location: /admin/plugins?tab=available');
                    exit;
                }
                file_put_contents($tmpFile, $data);

                $result = packageInstallZip($tmpFile, 'plugin');
                @unlink($tmpFile);

                if (!is_string($result)) {
                    $isNew = empty($result['_updated']);
                    if ($isNew) {
                        $pSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower($result['name']));
                        $pFile = ESSE_ROOT . '/plugins/' . $pSlug . '/Plugin.php';
                        if (file_exists($pFile)) {
                            require_once $pFile;
                            $class = $result['class'] ?? null;
                            if ($class && class_exists($class)) {
                                try { (new $class())->install(); } catch (\Throwable $e) {}
                            }
                        }
                    }
                    AuditLog::record(
                        $isNew ? 'plugin_installed' : 'plugin_updated',
                        Auth::id(),
                        Auth::user()['email'] ?? null,
                        ['plugin' => $result['name'], 'version' => $result['version'], 'source' => 'repo']
                    );
                    Flash::set('success', $isNew
                        ? "Plugin '{$result['name']}' v{$result['version']} installiert."
                        : "Plugin '{$result['name']}' auf v{$result['version']} aktualisiert.");
                } else {
                    AuditLog::record('plugin_install_failed', Auth::id(), Auth::user()['email'] ?? null, ['source' => 'repo', 'repo' => $fullName, 'reason' => $result]);
                    Flash::set('danger', $result);
                }
            } else {
                AuditLog::record('plugin_install_failed', Auth::id(), Auth::user()['email'] ?? null, ['source' => 'repo', 'repo' => $fullName, 'reason' => 'no_release']);
                Flash::set('danger', 'Kein Release gefunden.');
            }
        }
        header('Location: /admin/plugins?tab=available');
        exit;
    }

    // ZIP upload — Direct-Upload eines beliebigen ZIPs ist riskanter als Installation aus einem
    // geprüften Repo-Kanal, daher zusätzlich auf Forge beschränkt (manage_plugins reicht hier nicht).
    if ($action === 'upload' && !empty($_FILES['plugin_zip']['tmp_name'])) {
        if (!Auth::meetsRole('forge')) {
            AuditLog::record('plugin_install_failed', Auth::id(), Auth::user()['email'] ?? null, ['source' => 'upload', 'reason' => 'forbidden_role']);
            http_response_code(403); echo '403 Forbidden'; exit;
        }
        $result = packageInstallZip($_FILES['plugin_zip']['tmp_name'], 'plugin');
        if (!is_string($result)) {
            $isNew = empty($result['_updated']);
            // Call install() on new installations (not on updates)
            if ($isNew) {
                $slug       = preg_replace('/[^a-z0-9\-]/', '', strtolower($result['name']));
                $pluginFile = ESSE_ROOT . '/plugins/' . $slug . '/Plugin.php';
                if (file_exists($pluginFile)) {
                    require_once $pluginFile;
                    $class = $result['class'] ?? null;
                    if ($class && class_exists($class)) {
                        try { (new $class())->install(); } catch (\Throwable $e) {}
                    }
                }
            }
            AuditLog::record(
                $isNew ? 'plugin_installed' : 'plugin_updated',
                Auth::id(),
                Auth::user()['email'] ?? null,
                ['plugin' => $result['name'], 'version' => $result['version'], 'source' => 'upload']
            );
            Flash::set('success', $isNew
                ? "Plugin '{$result['name']}' v{$result['version']} installiert."
                : "Plugin '{$result['name']}' auf v{$result['version']} aktualisiert.");
        } else {
            AuditLog::record('plugin_install_failed', Auth::id(), Auth::user()['email'] ?? null, ['source' => 'upload', 'reason' => $result]);
            Flash::set('danger', $result);
        }
        header('Location: /admin/plugins');
        exit;
    }
}

function saveEnabled(string $ts, array $enabled): void
{
    DB::query(
        "INSERT INTO `{$ts}` (`key`, `value`) VALUES ('enabled_plugins', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [json_encode(array_values($enabled))]
    );
}

// Cache refresh (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'refresh_cache') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }
    @unlink(ESSE_PRIVATE_PATH . '/storage/cache/plugin_repos.json');
    AuditLog::record('repo_cache_refreshed', Auth::id(), Auth::user()['email'] ?? null);
    header('Location: /admin/plugins?tab=available');
    exit;
}

// Load repo channels
$repos = DB::fetchAll("SELECT * FROM `{$tr}` ORDER BY trusted DESC, label ASC");

// Build a name→latest_version map from cache (if available) for update indicators
$latestVersionMap = [];
$repoCacheFile = ESSE_PRIVATE_PATH . '/storage/cache/plugin_repos.json';
if (!is_dir(dirname($repoCacheFile))) {
    @mkdir(dirname($repoCacheFile), 0750, true);
}
if (file_exists($repoCacheFile)) {
    $cached = json_decode(file_get_contents($repoCacheFile), true) ?: [];
    foreach ($cached as $item) {
        if (!empty($item['name']) && !empty($item['latest_version'])) {
            $latestVersionMap[$item['name']] = $item['latest_version'];
        }
    }
}

$pageTitle = 'Plugins';
$activeNav = 'plugins';

ob_start();
?>
<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'installed' ? 'active' : '' ?>"
           href="/admin/plugins?tab=installed">
            <i class="bi bi-puzzle me-1"></i>Installiert
            <?php if ($plugins): ?>
            <span class="badge bg-secondary ms-1"><?= count($plugins) ?></span>
            <?php endif ?>
            <?php
            $updateCount = 0;
            foreach ($plugins as $p) {
                $latest = $latestVersionMap[$p['name'] ?? ''] ?? null;
                if ($latest && !empty($p['version']) &&
                    version_compare(ltrim($latest,'v'), ltrim($p['version'],'v'), '>')) {
                    $updateCount++;
                }
            }
            if ($updateCount > 0):
            ?>
            <span class="badge bg-warning text-dark ms-1"><?= $updateCount ?> Update<?= $updateCount > 1 ? 's' : '' ?></span>
            <?php endif ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'available' ? 'active' : '' ?>"
           href="/admin/plugins?tab=available">
            <i class="bi bi-cloud-download me-1"></i>Verfügbar
        </a>
    </li>
</ul>

<?php if ($tab === 'available'): ?>
<!-- ── AVAILABLE TAB ── -->
<?php
// Load available plugins from all active repos (cached 1h)
$cacheFile   = ESSE_PRIVATE_PATH . '/storage/cache/plugin_repos.json';
$cachedRepos = null;
if (!is_dir(dirname($cacheFile))) {
    @mkdir(dirname($cacheFile), 0750, true);
}
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
    $cachedRepos = json_decode(file_get_contents($cacheFile), true);
}

if (!$cachedRepos) {
    $cachedRepos = [];
    foreach ($repos as $repo) {
        if (!$repo['active']) continue;
        $results = GitHubApi::searchPlugins($repo['owner'], (bool)$repo['trusted']);
        foreach ($results as $plugin) {
            $plugin['channel_label']   = $repo['label'];
            $plugin['channel_trusted'] = (bool)$repo['trusted'];
            // Fetch latest release version for update comparison
            $release = GitHubApi::latestRelease($plugin['full_name']);
            $plugin['latest_version'] = $release['version'] ?? null;
            $plugin['download_url']   = $release['download_url'] ?? null;
            $cachedRepos[] = $plugin;
        }
    }
    @file_put_contents($cacheFile, json_encode($cachedRepos));
}

// Map installed plugins by name for version comparison
$installedByName = [];
foreach ($plugins as $p) {
    if (!empty($p['name'])) $installedByName[$p['name']] = $p;
}
?>

<div class="row g-3 mb-4">
<?php foreach ($cachedRepos as $available):
    $installed    = $installedByName[$available['name']] ?? null;
    $isInstalled  = $installed !== null;
    $installedVer = $installed['version'] ?? null;
    $latestVer    = $available['latest_version'] ?? null;
    $hasUpdate    = $isInstalled && $latestVer && $installedVer
                    && version_compare(ltrim($latestVer,'v'), ltrim($installedVer,'v'), '>');
?>
<div class="col-lg-6">
    <div class="card h-100 <?= $isInstalled ? 'border-success' : '' ?>">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <strong><?= htmlspecialchars($available['name']) ?></strong>
                <?php if ($available['channel_trusted']): ?>
                <span class="badge bg-success badge-xs">
                    <i class="bi bi-shield-check"></i> Offiziell
                </span>
                <?php else: ?>
                <span class="badge bg-warning text-dark badge-xs">
                    <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($available['channel_label']) ?>
                </span>
                <?php endif ?>
            </div>
            <?php if ($hasUpdate): ?>
            <span class="badge bg-warning text-dark">
                <i class="bi bi-arrow-up-circle"></i> Update: v<?= htmlspecialchars($latestVer) ?>
            </span>
            <?php elseif ($isInstalled): ?>
            <span class="badge bg-success">
                v<?= htmlspecialchars($installedVer) ?> — Aktuell
            </span>
            <?php endif ?>
        </div>
        <div class="card-body">
            <?php if ($available['description']): ?>
            <p class="text-secondary small mb-3"><?= htmlspecialchars($available['description']) ?></p>
            <?php endif ?>
            <div class="d-flex gap-2">
                <form method="post" action="/admin/plugins"
                      <?= !$available['channel_trusted'] ? 'data-confirm="Achtung: Nicht-offizieller Kanal. Nur installieren wenn du der Quelle vertraust!"' : '' ?>>
                    <input type="hidden" name="_csrf"           value="<?= Auth::csrfToken() ?>">
                    <input type="hidden" name="_action"         value="install_from_repo">
                    <input type="hidden" name="repo_full_name"  value="<?= htmlspecialchars($available['full_name']) ?>">
                    <?php if (!$isInstalled): ?>
                    <button class="btn btn-sm btn-primary">
                        <i class="bi bi-download"></i> Installieren
                        <?php if ($latestVer): ?>(v<?= htmlspecialchars($latestVer) ?>)<?php endif ?>
                    </button>
                    <?php elseif ($hasUpdate): ?>
                    <button class="btn btn-sm btn-warning text-dark">
                        <i class="bi bi-arrow-up-circle"></i> Update auf v<?= htmlspecialchars($latestVer) ?>
                    </button>
                    <?php else: ?>
                    <button class="btn btn-sm btn-outline-secondary" disabled>
                        <i class="bi bi-check"></i> Aktuell
                    </button>
                    <?php endif ?>
                </form>
                <a href="<?= htmlspecialchars($available['html_url']) ?>" target="_blank"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-github"></i>
                </a>
            </div>
        </div>
    </div>
</div>
<?php endforeach ?>
</div>

<?php if (empty($cachedRepos)): ?>
<div class="alert alert-secondary">
    Keine Plugins gefunden. Stelle sicher dass die Plugin-Repos das Topic <code>esse-plugin</code> auf GitHub haben.
</div>
<?php endif ?>

<!-- Kanäle werden zentral unter /admin/repos verwaltet (gilt für Plugins, Themes, Icon-Packs) -->
<div class="card mt-4">
    <div class="card-body d-flex justify-content-between align-items-center">
        <small class="text-secondary">
            Durchsucht <?= count($repos) ?> Kanal<?= count($repos) === 1 ? '' : 'e' ?> nach dem Topic <code>esse-plugin</code>.
        </small>
        <div class="d-flex gap-2">
            <form method="post" action="/admin/plugins?tab=available" class="d-inline">
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
</div>

<?php else: ?>
<!-- ── INSTALLED TAB ── -->

<!-- Plugin list -->
<?php if ($plugins): ?>
<div class="row g-3">
    <?php foreach ($plugins as $slug => $meta):
        $latestV   = $latestVersionMap[$meta['name'] ?? ''] ?? null;
        $hasUpdate = $latestV && !empty($meta['version']) &&
                     version_compare(ltrim($latestV,'v'), ltrim($meta['version'],'v'), '>');
    ?>
    <div class="col-lg-6">
        <div class="card h-100 <?= $hasUpdate ? 'border-warning' : ($meta['enabled'] ? 'border-success' : '') ?>">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <div>
                    <strong><?= htmlspecialchars($meta['name']) ?></strong>
                    <small class="text-secondary ms-2">v<?= htmlspecialchars($meta['version'] ?? '—') ?></small>
                    <?php if ($hasUpdate): ?>
                    <small class="text-warning ms-1">
                        <i class="bi bi-arrow-up-circle-fill"></i> v<?= htmlspecialchars($latestV) ?> verfügbar
                    </small>
                    <?php endif ?>
                </div>
                <?php if ($meta['enabled']): ?>
                    <span class="badge bg-success">Aktiv</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Inaktiv</span>
                <?php endif ?>
            </div>
            <div class="card-body">
                <p class="text-secondary small mb-3">
                    <?= htmlspecialchars($meta['description'] ?? '') ?>
                    <?php if (!empty($meta['author'])): ?>
                        — <em><?= htmlspecialchars($meta['author']) ?></em>
                    <?php endif ?>
                </p>
                <div class="d-flex gap-2">
                    <?php if ($meta['enabled']): ?>
                    <form method="post">
                        <input type="hidden" name="_csrf"        value="<?= Auth::csrfToken() ?>">
                        <input type="hidden" name="_action"      value="disable">
                        <input type="hidden" name="plugin_slug"  value="<?= htmlspecialchars($slug) ?>">
                        <button class="btn btn-sm btn-outline-warning">Deaktivieren</button>
                    </form>
                    <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="_csrf"        value="<?= Auth::csrfToken() ?>">
                        <input type="hidden" name="_action"      value="enable">
                        <input type="hidden" name="plugin_slug"  value="<?= htmlspecialchars($slug) ?>">
                        <button class="btn btn-sm btn-outline-success">Aktivieren</button>
                    </form>
                    <?php endif ?>
                    <form method="post"
                          data-confirm="Plugin &quot;<?= htmlspecialchars($meta['name']) ?>&quot; wirklich deinstallieren? Alle Dateien werden gelöscht.">
                        <input type="hidden" name="_csrf"        value="<?= Auth::csrfToken() ?>">
                        <input type="hidden" name="_action"      value="uninstall">
                        <input type="hidden" name="plugin_slug"  value="<?= htmlspecialchars($slug) ?>">
                        <button class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach ?>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body text-center text-secondary py-5">
        Noch keine Plugins installiert. Lade eine ZIP-Datei hoch oder wechsle zu
        <a href="/admin/plugins?tab=available">Verfügbar</a>.
    </div>
</div>
<?php endif ?>

<!-- ZIP Upload — nur Forge, Installation aus Repo-Kanälen bleibt für manage_plugins-Admins.
     Bewusst nur für den Notfall/Offline-Fall gedacht, daher unten angeordnet (wie bei Themes). -->
<?php if (Auth::meetsRole('forge')): ?>
<div class="card mt-4">
    <div class="card-header py-2"><small class="text-secondary">Plugin installieren</small></div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-end">
            <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="_action" value="upload">
            <div class="flex-grow-1">
                <input type="file" name="plugin_zip" class="form-control" accept=".zip" required>
                <div class="form-text">ZIP-Datei mit <code>plugin.json</code> und <code>Plugin.php</code></div>
            </div>
            <button class="btn btn-primary">
                <i class="bi bi-upload"></i> Installieren
            </button>
        </form>
    </div>
</div>
<?php endif ?>

<?php endif /* tab check */ ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
