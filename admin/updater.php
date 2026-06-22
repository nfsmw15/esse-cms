<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;
use Esse\Flash;
use Esse\Updater;

// Updates legen automatisch ein Backup an und verändern Code/Dateien — beide Rechte nötig.
if (!Auth::meetsRole('forge') && !(Auth::can('manage_updates') && Auth::can('manage_backups'))) {
    http_response_code(403); echo '403 Forbidden'; exit;
}

$flash = Flash::consume();

// Generate one-time run token for SSE update stream
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'prepare_run') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }
    $token = bin2hex(random_bytes(16));
    $_SESSION['update_run_token'] = $token;
    AuditLog::record('update_prepare', Auth::id(), Auth::user()['email'] ?? null);
    header('Content-Type: application/json');
    echo json_encode(['token' => $token]);
    exit;
}

// Update check cache refresh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'refresh_cache') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }
    @unlink(ESSE_PRIVATE_PATH . '/storage/cache/update_check.json');
    header('Location: /admin/update');
    exit;
}

// Pre-release toggle (POST saves to session)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prerelease_toggle'])) {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }
    $ts = \Esse\DB::table('settings');
    $old = \Esse\DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'update_prerelease'");
    $val = isset($_POST['include_prerelease']) ? '1' : '0';
    \Esse\DB::query(
        "INSERT INTO `{$ts}` (`key`, `value`) VALUES ('update_prerelease','$val')
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
    );
    if (($old ?? '0') !== $val) {
        AuditLog::record('settings_changed', Auth::id(), Auth::user()['email'] ?? null, [
            'update_prerelease' => ['old' => $old ?? '0', 'new' => $val],
        ]);
    }
    @unlink(ESSE_PRIVATE_PATH . '/storage/cache/update_check.json');
    header('Location: /admin/update');
    exit;
}

$ts             = \Esse\DB::table('settings');
$includePrerel  = \Esse\DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'update_prerelease'") === '1';

// Check for updates on page load (cached 1h)
$cacheFile   = ESSE_PRIVATE_PATH . '/storage/cache/update_check.json';
$updateInfo  = null;
$checkError  = null;
$hasUpdate   = false;
$cacheDir    = dirname($cacheFile);
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0750, true);
}

$cached = null;
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
    $cached = json_decode(file_get_contents($cacheFile), true);
}

if ($cached) {
    $updateInfo = $cached;
} else {
    $updateInfo = Updater::checkForUpdate($includePrerel);
    if ($updateInfo) {
        @file_put_contents($cacheFile, json_encode($updateInfo));
    } else {
        $checkError = 'GitHub nicht erreichbar oder keine Releases vorhanden.';
    }
}

if ($updateInfo) {
    $hasUpdate = Updater::isNewer($updateInfo['version'], ESSE_VERSION);
}

$pageTitle = 'System-Update';
$activeNav = 'update';

ob_start();
?>
<div class="row g-4">
    <div class="col-lg-7">

        <!-- Current version -->
        <div class="card mb-4">
            <div class="card-header py-3">
                <strong>Installierte Version</strong>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-3">
                    <span class="fs-4 fw-bold font-monospace"><?= ESSE_VERSION ?></span>
                    <?php if ($hasUpdate): ?>
                        <span class="badge bg-warning text-dark">Update verfügbar</span>
                    <?php elseif ($updateInfo): ?>
                        <span class="badge bg-success">Aktuell</span>
                <?php endif ?>
                </div>
                <div class="mt-2">
                    <form method="post" action="/admin/update" class="d-inline">
                        <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                        <input type="hidden" name="_action" value="refresh_cache">
                        <button class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-clockwise"></i> Erneut prüfen
                        </button>
                    </form>
                </div>
                <?php if ($checkError): ?>
                <div class="alert alert-secondary mt-3 mb-0 small"><?= htmlspecialchars($checkError) ?></div>
                <?php endif ?>
            </div>
        </div>

        <?php if ($hasUpdate && $updateInfo): ?>
        <!-- Update available -->
        <div class="card mb-4 border-warning">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <strong>ESSE CMS <?= htmlspecialchars($updateInfo['version']) ?></strong>
                    <?php if (!empty($updateInfo['prerelease'])): ?>
                    <span class="badge bg-secondary">Pre-Release</span>
                    <?php endif ?>
                </div>
                <small class="text-secondary">
                    <?= htmlspecialchars(date('d.m.Y', strtotime($updateInfo['published_at']))) ?>
                </small>
            </div>
            <div class="card-body">
                <?php if ($updateInfo['changelog']): ?>
                <div class="mb-3 small text-secondary admin-pre-wrap"><?= htmlspecialchars($updateInfo['changelog']) ?></div>
                <?php endif ?>
                <div class="alert alert-warning small mb-3">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    Vor dem Update wird automatisch ein Backup erstellt.
                    Eigene Plugins, Themes und Konfigurationsdateien werden nicht überschrieben.
                </div>
                <button id="btn-update" class="btn btn-warning"
                        data-version="<?= htmlspecialchars($updateInfo['version']) ?>">
                    <i class="bi bi-cloud-download"></i>
                    Update auf <?= htmlspecialchars($updateInfo['version']) ?> installieren
                </button>
                <a href="<?= htmlspecialchars($updateInfo['html_url']) ?>" target="_blank"
                   class="btn btn-outline-secondary ms-2">
                    <i class="bi bi-github"></i> Release-Notes
                </a>
            </div>
        </div>
        <?php elseif ($updateInfo && !$hasUpdate): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle-fill me-2"></i>
            ESSE CMS ist auf dem neuesten Stand (<?= htmlspecialchars($updateInfo['version']) ?>).
        </div>
        <?php endif ?>

        <!-- SSE Terminal -->
        <div id="terminal-wrap" class="admin-hidden">
            <div class="card border-secondary">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <small class="text-secondary font-monospace">Update-Fortschritt</small>
                    <span id="terminal-status" class="badge bg-secondary">Läuft...</span>
                </div>
                <div id="terminal"
                     class="font-monospace small p-3 admin-terminal">
                </div>
            </div>
        </div>

    </div>

    <!-- Right: Backups -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header py-2"><small class="text-secondary">Vorhandene Backups</small></div>
            <div class="card-body p-0">
                <?php
                $backupDir = ESSE_PRIVATE_PATH . '/storage/backups';
                $backups   = glob($backupDir . '/*.zip') ?: [];
                rsort($backups);
                $backups = array_slice($backups, 0, 10);
                ?>
                <?php if ($backups): ?>
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php foreach ($backups as $b): ?>
                    <tr>
                        <td class="small"><?= htmlspecialchars(basename($b)) ?></td>
                        <td class="text-secondary small text-end">
                            <?= round(filesize($b) / 1024) ?> KB
                        </td>
                    </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="p-3 text-secondary small">Noch keine Backups vorhanden.</div>
                <?php endif ?>
            </div>
        </div>

        <!-- Pre-release toggle -->
        <div class="card mt-3">
            <div class="card-header py-2"><small class="text-secondary">Update-Kanal</small></div>
            <div class="card-body">
                <form method="post" action="/admin/update">
                    <input type="hidden" name="_csrf"              value="<?= Auth::csrfToken() ?>">
                    <input type="hidden" name="prerelease_toggle"  value="1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="include_prerelease"
                               id="prerelease-toggle"
                               <?= $includePrerel ? 'checked' : '' ?>
                               data-submit-on-change>
                        <label class="form-check-label" for="prerelease-toggle">
                            Pre-Release Updates einschließen
                        </label>
                    </div>
                    <?php if ($includePrerel): ?>
                    <div class="alert alert-warning py-2 px-3 mt-2 mb-0 small">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        Pre-Releases sind nicht für den Produktionseinsatz geeignet und können Fehler enthalten.
                    </div>
                    <?php endif ?>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
$extraScriptConfig = ['admin-updater-config' => [
    'csrf' => \Esse\Auth::csrfToken(),
    'version' => $updateInfo['version'] ?? '',
]];
$extraScriptFiles = ['/public/assets/js/admin-updater.js'];
require __DIR__ . '/layout.php';
