<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;

if (!Auth::can('manage_settings')) {
    http_response_code(403); echo '403 Forbidden'; exit;
}

$ts = DB::table('settings');
$tp = DB::table('pages');

// Load all settings into a flat array
$rows     = DB::fetchAll("SELECT `key`, `value` FROM `{$ts}`");
$settings = array_column($rows, 'value', 'key');

$pages  = DB::fetchAll("SELECT slug, title FROM `{$tp}` WHERE status = 'published' ORDER BY title ASC");

// Discover installed themes
$themes = [];
foreach (glob(ESSE_ROOT . '/themes/*/theme.json') ?: [] as $jsonFile) {
    $meta = json_decode(file_get_contents($jsonFile), true);
    if (!empty($meta['name'])) {
        $themes[$meta['name']] = $meta['name'] . ' — ' . ($meta['description'] ?? '');
    }
}

$errors = [];

$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $save = [
        'site_name'     => trim($_POST['site_name']     ?? ''),
        'site_url'      => rtrim(trim($_POST['site_url'] ?? ''), '/'),
        'homepage_slug' => trim($_POST['homepage_slug'] ?? ''),
        'admin_email'   => trim($_POST['admin_email']   ?? ''),
        'active_theme'  => trim($_POST['active_theme']  ?? ''),
    ];

    if (!$save['site_name']) $errors[] = 'Seitenname ist Pflichtfeld.';
    if (!filter_var($save['site_url'], FILTER_VALIDATE_URL)) $errors[] = 'Ungültige URL.';

    if (empty($errors)) {
        foreach ($save as $key => $value) {
            DB::query(
                "INSERT INTO `{$ts}` (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$key, $value]
            );
        }
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Einstellungen gespeichert.'];
        header('Location: /admin/settings');
        exit;
    }
}

$pageTitle = 'Einstellungen';
$activeNav = 'settings';

ob_start();
?>
<?php if ($errors): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach ?>
</div>
<?php endif ?>

<form method="post" action="/admin/settings">
    <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
    <div class="row g-4">
        <div class="col-lg-8">

            <div class="card mb-4">
                <div class="card-header py-2"><small class="text-secondary">Allgemein</small></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Seitenname</label>
                        <input type="text" name="site_name" class="form-control"
                               value="<?= htmlspecialchars($settings['site_name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">URL</label>
                        <input type="url" name="site_url" class="form-control"
                               value="<?= htmlspecialchars($settings['site_url'] ?? '') ?>" required>
                        <div class="form-text">Ohne abschließenden Slash, z.B. <code>https://example.com</code></div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Admin-E-Mail</label>
                        <input type="email" name="admin_email" class="form-control"
                               value="<?= htmlspecialchars($settings['admin_email'] ?? '') ?>">
                        <div class="form-text">Für Systembenachrichtigungen (z.B. via PHPMailer-Plugin)</div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header py-2"><small class="text-secondary">Startseite</small></div>
                <div class="card-body">
                    <label class="form-label">Welche Seite erscheint unter <code>/</code>?</label>
                    <select name="homepage_slug" class="form-select">
                        <option value="">— keine (Platzhalter) —</option>
                        <?php foreach ($pages as $page): ?>
                        <option value="<?= htmlspecialchars($page['slug']) ?>"
                            <?= ($settings['homepage_slug'] ?? '') === $page['slug'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($page['title']) ?>
                            (<?= htmlspecialchars($page['slug']) ?>)
                        </option>
                        <?php endforeach ?>
                    </select>
                    <div class="form-text">Die gewählte Seite wird unter der Hauptdomain angezeigt.</div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header py-2"><small class="text-secondary">Theme</small></div>
                <div class="card-body">
                    <select name="active_theme" class="form-select">
                        <option value="">— kein Theme (reines HTML) —</option>
                        <?php foreach ($themes as $slug => $label): ?>
                        <option value="<?= htmlspecialchars($slug) ?>"
                            <?= ($settings['active_theme'] ?? '') === $slug ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                        <?php endforeach ?>
                    </select>
                    <div class="form-text">
                        Das aktive Theme bestimmt das Aussehen aller Frontend-Seiten.
                    </div>
                </div>
            </div>

            <button class="btn btn-primary">
                <i class="bi bi-floppy"></i> Speichern
            </button>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header py-2"><small class="text-secondary">System-Info</small></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td class="text-secondary">ESSE CMS</td>
                            <td><?= ESSE_VERSION ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">PHP</td>
                            <td><?= PHP_VERSION ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Server</td>
                            <td><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? '—') ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</form>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
