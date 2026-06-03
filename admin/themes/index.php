<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;
use Esse\GitHubApi;

// Theme installation = PHP code execution — require explicit permission or Forge role
if (!Auth::meetsRole('forge') && !Auth::can('manage_themes')) {
    http_response_code(403); echo '403 Forbidden'; exit;
}

require_once dirname(__DIR__) . '/package-install.php';

$ts  = DB::table('settings');
$tm  = DB::table('menus');
$tab = $_GET['tab'] ?? 'installed';

// Cache refresh (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'refresh_cache') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }
    @unlink(ESSE_PRIVATE_PATH . '/storage/cache/theme_repos.json');
    header('Location: /admin/themes?tab=available');
    exit;
}

$rows        = DB::fetchAll("SELECT `key`, `value` FROM `{$ts}`");
$settings    = array_column($rows, 'value', 'key');
$activeTheme = $settings['active_theme'] ?? '';
$allMenus    = DB::fetchAll("SELECT slug, name FROM `{$tm}` ORDER BY name ASC");

// Discover all installed themes
$themes = [];
foreach (glob(ESSE_ROOT . '/themes/*/theme.json') ?: [] as $jsonFile) {
    $meta = json_decode(file_get_contents($jsonFile), true);
    if (!empty($meta['name'])) {
        $themes[$meta['name']] = $meta;
    }
}

$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// POST: activate theme or save menu positions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $action = $_POST['_action'] ?? '';

    if ($action === 'activate') {
        $name = $_POST['theme_name'] ?? '';
        if (isset($themes[$name])) {
            DB::query(
                "INSERT INTO `{$ts}` (`key`, `value`) VALUES ('active_theme', ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$name]
            );
            $_SESSION['flash'] = ['type' => 'success', 'message' => "Theme '{$name}' aktiviert."];
        }
        header('Location: /admin/themes');
        exit;
    }

    // Install from repo
    if ($action === 'install_from_repo') {
        $fullName = trim($_POST['repo_full_name'] ?? '');
        if ($fullName && preg_match('#^[a-zA-Z0-9\-]+/[a-zA-Z0-9\-\.]+$#', $fullName)) {
            $release = GitHubApi::latestRelease($fullName);
            if ($release && $release['download_url']) {
                $tmpFile = ESSE_PRIVATE_PATH . '/storage/update_tmp/theme_' . uniqid() . '.zip';
                $dir = dirname($tmpFile);
                if (!is_dir($dir)) mkdir($dir, 0750, true);
                $ch = curl_init($release['download_url']);
                curl_setopt_array($ch, [\CURLOPT_RETURNTRANSFER => true, \CURLOPT_FOLLOWLOCATION => true, \CURLOPT_TIMEOUT => 30, \CURLOPT_USERAGENT => 'ESSE-CMS/' . \ESSE_VERSION, \CURLOPT_FAILONERROR => true]);
                $data = curl_exec($ch);
                $code = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
                if ($data === false || $code < 200 || $code >= 300 || strlen($data) < 100 || substr($data, 0, 2) !== 'PK') {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Download fehlgeschlagen oder ungültige ZIP-Datei.'];
                    header('Location: /admin/themes?tab=available'); exit;
                }
                file_put_contents($tmpFile, $data);
                $result = packageInstallZip($tmpFile, 'theme');
                @unlink($tmpFile);
                $_SESSION['flash'] = is_string($result)
                    ? ['type' => 'danger',  'message' => $result]
                    : ['type' => 'success', 'message' => empty($result['_updated'])
                        ? "Theme '{$result['name']}' v{$result['version']} installiert."
                        : "Theme '{$result['name']}' auf v{$result['version']} aktualisiert."];
            } else {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Kein Release gefunden.'];
            }
        }
        header('Location: /admin/themes?tab=available');
        exit;
    }

    if ($action === 'upload_theme' && !empty($_FILES['theme_zip']['tmp_name'])) {
        $result = packageInstallZip($_FILES['theme_zip']['tmp_name'], 'theme');
        $_SESSION['flash'] = is_string($result)
            ? ['type' => 'danger',  'message' => $result]
            : ['type' => 'success', 'message' => empty($result['_updated'])
                ? "Theme '{$result['name']}' v{$result['version']} installiert."
                : "Theme '{$result['name']}' auf v{$result['version']}' aktualisiert."];
        header('Location: /admin/themes');
        exit;
    }

    if ($action === 'save_menus') {
        $themeName = $_POST['theme_name'] ?? '';
        $meta      = $themes[$themeName] ?? null;
        if ($meta) {
            // Save menu positions
            foreach (array_keys($meta['menus'] ?? []) as $pos) {
                $key   = 'theme_' . $themeName . '_menu_' . $pos;
                $value = trim($_POST[$key] ?? '');
                DB::query(
                    "INSERT INTO `{$ts}` (`key`, `value`) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                    [$key, $value]
                );
            }
        }
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Theme-Einstellungen gespeichert.'];
        header('Location: /admin/themes');
        exit;
    }
}

// Build installed name→version map for update comparison
$installedByName = [];
foreach ($themes as $n => $m) { if (!empty($m['version'])) $installedByName[$n] = $m['version']; }

$pageTitle = 'Themes';
$activeNav = 'themes';

ob_start();
?>
<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'installed' ? 'active' : '' ?>" href="/admin/themes?tab=installed">
            <i class="bi bi-palette me-1"></i>Installiert
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'available' ? 'active' : '' ?>" href="/admin/themes?tab=available">
            <i class="bi bi-cloud-download me-1"></i>Verfügbar
        </a>
    </li>
</ul>

<?php if ($tab === 'available'): ?>
<?php
$cacheFile = ESSE_PRIVATE_PATH . '/storage/cache/theme_repos.json';
$available = null;
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
    $available = json_decode(file_get_contents($cacheFile), true);
}
if (!$available) {
    $available = GitHubApi::searchThemes('nfsmw15', true);
    foreach ($available as &$r) {
        $rel = GitHubApi::latestRelease($r['full_name']);
        $r['latest_version'] = $rel['version'] ?? null;
        $r['download_url']   = $rel['download_url'] ?? null;
    }
    unset($r);
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
                <span class="badge bg-success" style="font-size:.65rem"><i class="bi bi-shield-check"></i> Offiziell</span>
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
                <form method="post" action="/admin/themes">
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
<div class="alert alert-secondary">Keine Themes gefunden. Repos müssen das Topic <code>esse-theme</code> auf GitHub haben.</div>
<?php endif ?>
<div class="text-end mb-4">
    <form method="post" action="/admin/themes?tab=available" class="d-inline">
        <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
        <input type="hidden" name="_action" value="refresh_cache">
        <button class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-clockwise"></i> Cache leeren
        </button>
    </form>
</div>

<?php else: ?>
<!-- INSTALLED TAB -->
<?php if (!$themes): ?>
<div class="alert alert-secondary">Keine Themes installiert.</div>
<?php endif ?>

<div class="row g-4">
<?php foreach ($themes as $name => $meta): ?>
<?php $isActive = $name === $activeTheme; ?>
<div class="col-lg-6">
    <div class="card h-100 <?= $isActive ? 'border-primary' : '' ?>">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <div>
                <strong><?= htmlspecialchars($meta['name']) ?></strong>
                <small class="text-secondary ms-2">v<?= htmlspecialchars($meta['version'] ?? '—') ?></small>
            </div>
            <?php if ($isActive): ?>
                <span class="badge bg-primary">Aktiv</span>
            <?php else: ?>
                <form method="post" action="/admin/themes" class="d-inline">
                    <input type="hidden" name="_csrf"       value="<?= Auth::csrfToken() ?>">
                    <input type="hidden" name="_action"     value="activate">
                    <input type="hidden" name="theme_name"  value="<?= htmlspecialchars($name) ?>">
                    <button class="btn btn-sm btn-outline-primary">Aktivieren</button>
                </form>
            <?php endif ?>
        </div>
        <div class="card-body">
            <p class="text-secondary small mb-3">
                <?= htmlspecialchars($meta['description'] ?? '') ?>
                <?php if (!empty($meta['author'])): ?>
                    — <em><?= htmlspecialchars($meta['author']) ?></em>
                <?php endif ?>
            </p>

            <?php if ($isActive && !empty($meta['menus'])): ?>
            <form method="post" action="/admin/themes">
                <input type="hidden" name="_csrf"      value="<?= Auth::csrfToken() ?>">
                <input type="hidden" name="_action"    value="save_menus">
                <input type="hidden" name="theme_name" value="<?= htmlspecialchars($name) ?>">

                <p class="small text-secondary mb-2 fw-semibold">Menüpositionen</p>
                <?php foreach ($meta['menus'] as $pos => $label): ?>
                <?php $key = 'theme_' . $name . '_menu_' . $pos; ?>
                <div class="mb-2">
                    <label class="form-label small"><?= htmlspecialchars($label) ?></label>
                    <select name="<?= htmlspecialchars($key) ?>" class="form-select form-select-sm">
                        <option value="">— kein Menü —</option>
                        <?php foreach ($allMenus as $m): ?>
                        <option value="<?= htmlspecialchars($m['slug']) ?>"
                            <?= ($settings[$key] ?? '') === $m['slug'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['name']) ?>
                        </option>
                        <?php endforeach ?>
                    </select>
                </div>
                <?php endforeach ?>


                <button class="btn btn-sm btn-primary mt-1">
                    <i class="bi bi-floppy"></i> Speichern
                </button>
                <a href="/admin/menus" class="btn btn-sm btn-outline-secondary mt-1">
                    <i class="bi bi-list-nested"></i> Menüs verwalten
                </a>
            </form>
            <?php elseif ($isActive): ?>
            <p class="text-secondary small">Dieses Theme hat keine konfigurierbaren Menüpositionen.</p>
            <?php endif ?>
        </div>
    </div>
</div>
<?php endforeach ?>
</div>
<!-- Theme ZIP Upload -->
<div class="card mt-4">
    <div class="card-header py-2"><small class="text-secondary">Theme installieren</small></div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-end"
              action="/admin/themes">
            <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="_action" value="upload_theme">
            <div class="flex-grow-1">
                <input type="file" name="theme_zip" class="form-control" accept=".zip" required>
                <div class="form-text">ZIP mit <code>theme.json</code> und <code>Theme.php</code></div>
            </div>
            <button class="btn btn-primary">
                <i class="bi bi-upload"></i> Installieren
            </button>
        </form>
    </div>
</div>
<?php endif /* tab check */ ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
