<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;

require_once dirname(__DIR__) . '/package-install.php';

$ts = DB::table('settings');
$tm = DB::table('menus');

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
            // Save public slugs if theme supports it
            if (!empty($meta['settings']['public_slugs']) || isset($_POST['theme_' . $themeName . '_public_slugs'])) {
                $slugsKey = 'theme_' . $themeName . '_public_slugs';
                $slugsVal = trim($_POST[$slugsKey] ?? '');
                DB::query(
                    "INSERT INTO `{$ts}` (`key`, `value`) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                    [$slugsKey, $slugsVal]
                );
            }
        }
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Theme-Einstellungen gespeichert.'];
        header('Location: /admin/themes');
        exit;
    }
}

$pageTitle = 'Themes';
$activeNav = 'themes';

ob_start();
?>
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

                <?php
                // Public slugs setting (e.g. for dashboard theme)
                $pubKey = 'theme_' . $name . '_public_slugs';
                $pubVal = $settings[$pubKey] ?? '';
                ?>
                <div class="mb-2 mt-3">
                    <label class="form-label small">Öffentliche Seiten <span class="text-secondary">(ohne Login zugänglich)</span></label>
                    <input type="text" name="<?= htmlspecialchars($pubKey) ?>"
                           class="form-control form-control-sm font-monospace"
                           value="<?= htmlspecialchars($pubVal) ?>"
                           placeholder="impressum, datenschutz">
                    <div class="form-text">Kommagetrennte Slugs — diese Seiten sind auch ohne Login sichtbar.</div>
                </div>

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
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
