<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;

if (!Auth::meetsRole('forge') && !Auth::can('manage_settings')) {
    http_response_code(403); echo '403 Forbidden'; exit;
}

require_once __DIR__ . '/package-install.php';

$ts = DB::table('settings');

$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

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
            $_SESSION['flash'] = ['type' => 'success', 'message' => "Icon-Pack '{$name}' aktiviert."];
        }
        header('Location: /admin/iconpacks');
        exit;
    }

    // Upload ZIP
    if ($action === 'upload' && !empty($_FILES['pack_zip']['tmp_name'])) {
        $result = installIconPack($_FILES['pack_zip']['tmp_name']);
        $_SESSION['flash'] = is_string($result)
            ? ['type' => 'danger',  'message' => $result]
            : ['type' => 'success', 'message' => "Icon-Pack '{$result['name']}' v{$result['version']} installiert."];
        header('Location: /admin/iconpacks');
        exit;
    }

    // Delete pack
    if ($action === 'delete') {
        $name = $_POST['pack_name'] ?? '';
        $packs = discoverIconPacks();
        $active = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'icon_pack'") ?? '';

        if ($name === $active) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Das aktive Icon-Pack kann nicht gelöscht werden.'];
        } elseif (isset($packs[$name]) && $packs[$name]['dir'] !== 'bootstrap-icons') {
            packageDeleteDir(ESSE_ROOT . '/public/vendor/' . $packs[$name]['dir']);
            $_SESSION['flash'] = ['type' => 'success', 'message' => "Icon-Pack '{$name}' gelöscht."];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dieses Pack kann nicht gelöscht werden.'];
        }
        header('Location: /admin/iconpacks');
        exit;
    }
}

function installIconPack(string $tmpFile): array|string
{
    $zip = new \ZipArchive();
    if ($zip->open($tmpFile) !== true) return 'ZIP konnte nicht geöffnet werden.';

    // Find iconpack.json
    $meta    = null;
    $rootDir = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (basename($name) === 'iconpack.json') {
            $meta    = json_decode($zip->getFromIndex($i), true);
            $parts   = explode('/', $name);
            $rootDir = count($parts) > 1 ? $parts[0] : '';
            break;
        }
    }

    if (!$meta || empty($meta['name']) || empty($meta['css'])) {
        $zip->close();
        return 'Keine gültige iconpack.json im ZIP gefunden.';
    }

    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($meta['name']));
    if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,63}$/', $slug)) {
        $zip->close();
        return "Ungültiger Pack-Name '{$meta['name']}'.";
    }

    $target = ESSE_ROOT . '/public/vendor/' . $slug;
    $tmp    = $target . '_tmp_' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $rel  = $rootDir ? preg_replace('#^' . preg_quote($rootDir, '#') . '/?#', '', $name) : $name;
        if ($rel === '' || str_ends_with($rel, '/') || str_contains($rel, '..')) continue;

        $dest = $tmp . '/' . $rel;
        if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
        file_put_contents($dest, $zip->getFromIndex($i));
    }
    $zip->close();

    if (is_dir($target)) packageDeleteDir($target);
    rename($tmp, $target);

    return $meta;
}

$packs  = discoverIconPacks();
$active = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'icon_pack'") ?? 'bootstrap-icons';

$pageTitle = 'Icon-Packs';
$activeNav = 'iconpacks';

ob_start();
?>
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
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
