<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\Updater;

if (!Auth::meetsRole('forge') && !Auth::can('manage_settings')) {
    http_response_code(403); echo '403 Forbidden'; exit;
}

$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// Check for updates on page load (cached 1h)
$cacheFile   = ESSE_PRIVATE_PATH . '/storage/cache/update_check.json';
$updateInfo  = null;
$checkError  = null;
$hasUpdate   = false;

$cached = null;
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
    $cached = json_decode(file_get_contents($cacheFile), true);
}

if ($cached) {
    $updateInfo = $cached;
} else {
    $updateInfo = Updater::checkForUpdate();
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
                    <a href="?refresh=1" class="btn btn-sm btn-outline-secondary"
                       onclick="localStorage.removeItem('updateChecked')">
                        <i class="bi bi-arrow-clockwise"></i> Erneut prüfen
                    </a>
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
                <strong>ESSE CMS <?= htmlspecialchars($updateInfo['version']) ?></strong>
                <small class="text-secondary">
                    <?= htmlspecialchars(date('d.m.Y', strtotime($updateInfo['published_at']))) ?>
                </small>
            </div>
            <div class="card-body">
                <?php if ($updateInfo['changelog']): ?>
                <div class="mb-3 small text-secondary" style="white-space:pre-wrap;max-height:200px;overflow-y:auto"><?= htmlspecialchars($updateInfo['changelog']) ?></div>
                <?php endif ?>
                <div class="alert alert-warning small mb-3">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    Vor dem Update wird automatisch ein Backup erstellt.
                    Eigene Plugins, Themes und Konfigurationsdateien werden nicht überschrieben.
                </div>
                <button id="btn-update" class="btn btn-warning"
                        onclick="startUpdate('<?= htmlspecialchars($updateInfo['version']) ?>')">
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
        <div id="terminal-wrap" style="display:none">
            <div class="card border-secondary">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <small class="text-secondary font-monospace">Update-Fortschritt</small>
                    <span id="terminal-status" class="badge bg-secondary">Läuft...</span>
                </div>
                <div id="terminal"
                     class="font-monospace small p-3"
                     style="background:#0a0a0a;min-height:200px;max-height:400px;overflow-y:auto;color:#4ade80">
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
    </div>
</div>

<?php if (isset($_GET['refresh'])): ?>
<script>
// Clear cache and reload without ?refresh param
fetch('/admin/update?refresh=1').then(() => {
    window.location.href = '/admin/update';
});
</script>
<?php endif ?>

<script>
function startUpdate(version) {
    if (!confirm('Update auf v' + version + ' wirklich starten? Ein Backup wird automatisch erstellt.')) return;

    document.getElementById('btn-update').disabled = true;
    document.getElementById('terminal-wrap').style.display = '';

    const terminal = document.getElementById('terminal');
    const status   = document.getElementById('terminal-status');

    const es = new EventSource('/admin/update/run');

    es.onmessage = (e) => {
        const data = JSON.parse(e.data);
        const line = document.createElement('div');

        if (data.type === 'success') {
            line.style.color = '#4ade80';
            line.textContent = '✓ ' + data.message;
        } else if (data.type === 'error') {
            line.style.color = '#f87171';
            line.textContent = '✗ ' + data.message;
        } else {
            line.style.color = '#94a3b8';
            line.textContent = '› ' + data.message;
        }

        terminal.appendChild(line);
        terminal.scrollTop = terminal.scrollHeight;

        if (data.type === 'done') {
            es.close();
            status.textContent = 'Abgeschlossen';
            status.className   = 'badge bg-success';
            setTimeout(() => window.location.reload(), 2000);
        }
        if (data.type === 'error') {
            es.close();
            status.textContent = 'Fehler';
            status.className   = 'badge bg-danger';
            document.getElementById('btn-update').disabled = false;
        }
    };

    es.onerror = () => {
        es.close();
        status.textContent = 'Verbindung unterbrochen';
        status.className   = 'badge bg-danger';
    };
}
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
