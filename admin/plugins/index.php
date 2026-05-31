<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;

require_once dirname(__DIR__) . '/package-install.php';

$ts = DB::table('settings');

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

$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// -- POST actions --
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $action = $_POST['_action'] ?? '';
    $slug   = basename($_POST['plugin_slug'] ?? '');

    // Enable / Disable
    if (in_array($action, ['enable', 'disable'], true) && $slug) {
        if ($action === 'enable' && !in_array($slug, $enabled, true)) {
            $enabled[] = $slug;
        } elseif ($action === 'disable') {
            $enabled = array_values(array_filter($enabled, fn($s) => $s !== $slug));
        }
        saveEnabled($ts, $enabled);
        $_SESSION['flash'] = ['type' => 'success', 'message' =>
            "Plugin '{$slug}' " . ($action === 'enable' ? 'aktiviert' : 'deaktiviert') . '.'];
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
        packageDeleteDir(ESSE_ROOT . '/plugins/' . $slug);
        $enabled = array_values(array_filter($enabled, fn($s) => $s !== $slug));
        saveEnabled($ts, $enabled);
        $_SESSION['flash'] = ['type' => 'success', 'message' => "Plugin '{$slug}' deinstalliert."];
        header('Location: /admin/plugins');
        exit;
    }

    // ZIP upload
    if ($action === 'upload' && !empty($_FILES['plugin_zip']['tmp_name'])) {
        $result = packageInstallZip($_FILES['plugin_zip']['tmp_name'], 'plugin');
        $_SESSION['flash'] = is_string($result)
            ? ['type' => 'danger',  'message' => $result]
            : ['type' => 'success', 'message' => empty($result['_updated'])
                ? "Plugin '{$result['name']}' v{$result['version']} installiert."
                : "Plugin '{$result['name']}' auf v{$result['version']} aktualisiert."];
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

$pageTitle = 'Plugins';
$activeNav = 'plugins';

ob_start();
?>
<!-- ZIP Upload -->
<div class="card mb-4">
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

<!-- Plugin list -->
<?php if ($plugins): ?>
<div class="row g-3">
    <?php foreach ($plugins as $slug => $meta): ?>
    <div class="col-lg-6">
        <div class="card h-100 <?= $meta['enabled'] ? 'border-success' : '' ?>">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <div>
                    <strong><?= htmlspecialchars($meta['name']) ?></strong>
                    <small class="text-secondary ms-2">v<?= htmlspecialchars($meta['version'] ?? '—') ?></small>
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
                          onsubmit="return confirm('Plugin &quot;<?= htmlspecialchars(addslashes($meta['name'])) ?>&quot; wirklich deinstallieren? Alle Dateien werden gelöscht.')">
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
        Noch keine Plugins installiert. Lade eine ZIP-Datei hoch um zu starten.
    </div>
</div>
<?php endif ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
