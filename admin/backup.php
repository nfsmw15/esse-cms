<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;
use Esse\Flash;
use Esse\Updater;

if (!Auth::meetsRole('forge') && !Auth::can('manage_backups')) {
    http_response_code(403); echo '403 Forbidden'; exit;
}

$backupDir = ESSE_PRIVATE_PATH . '/storage/backups';
if (!is_dir($backupDir)) mkdir($backupDir, 0750, true);

$flash = Flash::consume();

// -- POST actions --
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $action = $_POST['_action'] ?? '';

    // Create manual backup (SSE — redirect to SSE endpoint)
    if ($action === 'create') {
        try {
            $path = Updater::createBackup(fn() => null, 'manual');
            AuditLog::record('backup_created', Auth::id(), Auth::user()['email'] ?? null, [
                'file' => basename($path), 'size' => @filesize($path) ?: null,
            ]);
            Flash::set('success', 'Backup erstellt: ' . basename($path));
        } catch (\Throwable $e) {
            Flash::set('danger', 'Backup fehlgeschlagen: ' . $e->getMessage());
        }
        header('Location: /admin/backup');
        exit;
    }

    // Delete
    if ($action === 'delete') {
        $file = basename($_POST['file'] ?? '');
        $path = $backupDir . '/' . $file;
        if ($file && file_exists($path) && str_ends_with($file, '.zip')) {
            unlink($path);
            AuditLog::record('backup_deleted', Auth::id(), Auth::user()['email'] ?? null, ['file' => $file]);
            Flash::set('success', "Backup '{$file}' gelöscht.");
        }
        header('Location: /admin/backup');
        exit;
    }

    // Restore
    if ($action === 'restore') {
        $file      = basename($_POST['file'] ?? '');
        $path      = $backupDir . '/' . $file;
        $fullClean = ($_POST['full_clean'] ?? '1') === '1';
        if (!$file || !file_exists($path)) {
            Flash::set('danger', 'Backup nicht gefunden.');
            header('Location: /admin/backup');
            exit;
        }
        // Only Forge can restore
        if (!Auth::meetsRole('forge')) {
            http_response_code(403); exit;
        }
        // Große Backups (viele Datenzeilen) können trotz Transaktions-Batching länger als das
        // Standard-Request-Limit dauern — Restore ist eine bewusste, seltene Forge-Aktion.
        set_time_limit(0);
        try {
            Updater::restore($path, fn() => null, $fullClean);
            AuditLog::record('backup_restored', Auth::id(), Auth::user()['email'] ?? null, ['file' => $file, 'full_clean' => $fullClean]);
            Flash::set('success', "Wiederherstellung aus '{$file}' abgeschlossen.");
        } catch (\Throwable $e) {
            AuditLog::record('backup_restore_failed', Auth::id(), Auth::user()['email'] ?? null, ['file' => $file, 'error' => $e->getMessage()]);
            Flash::set('danger', 'Wiederherstellung fehlgeschlagen: ' . $e->getMessage());
        }
        header('Location: /admin/backup');
        exit;
    }
}

// List backups
$backups = glob($backupDir . '/*.zip') ?: [];
rsort($backups);

$pageTitle = 'Backups';
$activeNav = 'backup';

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-secondary mb-0 small">
        Backups liegen in <code><?= htmlspecialchars($backupDir) ?></code> — außerhalb des Webroots.
    </p>
    <form method="post" action="/admin/backup">
        <input type="hidden" name="_csrf"    value="<?= Auth::csrfToken() ?>">
        <input type="hidden" name="_action"  value="create">
        <button class="btn btn-primary btn-sm">
            <i class="bi bi-shield-plus"></i> Backup erstellen
        </button>
    </form>
</div>

<?php if ($backups): ?>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr>
                <th>Datei</th>
                <th>Größe</th>
                <th>Erstellt</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($backups as $b):
                $name = basename($b);
                $size = round(filesize($b) / 1024);
                $mtime = date('d.m.Y H:i', filemtime($b));
                // Extract version from filename if present
                preg_match('/_v([^_]+)_/', $name, $vm);
                $version = $vm[1] ?? null;
            ?>
            <tr class="align-middle">
                <td>
                    <span class="small font-monospace"><?= htmlspecialchars($name) ?></span>
                    <?php if ($version): ?>
                    <span class="badge bg-secondary ms-1 badge-xs"><?= htmlspecialchars($version) ?></span>
                    <?php endif ?>
                </td>
                <td class="text-secondary small"><?= $size ?> KB</td>
                <td class="text-secondary small"><?= $mtime ?></td>
                <td class="text-end">
                    <!-- Download -->
                    <a href="/admin/backup/download/<?= urlencode($name) ?>"
                       class="btn btn-sm btn-outline-secondary" title="Herunterladen">
                        <i class="bi bi-download"></i>
                    </a>

                    <!-- Restore (Forge only) -->
                    <?php if (Auth::meetsRole('forge')): ?>
                    <form method="post" action="/admin/backup" class="d-inline"
                          data-confirm="Backup '<?= htmlspecialchars($name) ?>' VOLLSTÄNDIG wiederherstellen?

Datenbank UND Dateien werden exakt auf den Backup-Zeitpunkt zurückgesetzt. Dateien, die seither neu hinzugekommen sind (z.B. Uploads), werden dabei entfernt — empfohlen für Produktivbetrieb, sonst können verwaiste Dateien öffentlich erreichbar bleiben.">
                        <input type="hidden" name="_csrf"       value="<?= Auth::csrfToken() ?>">
                        <input type="hidden" name="_action"     value="restore">
                        <input type="hidden" name="file"        value="<?= htmlspecialchars($name) ?>">
                        <input type="hidden" name="full_clean"  value="1">
                        <button class="btn btn-sm btn-outline-warning" title="Vollständig wiederherstellen (empfohlen) — entfernt auch Dateien, die nach dem Backup neu hinzugekommen sind">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </button>
                    </form>
                    <form method="post" action="/admin/backup" class="d-inline"
                          data-confirm="Backup '<?= htmlspecialchars($name) ?>' zusammenführen (Merge)?

Die Datenbank wird auf den Backup-Zeitpunkt zurückgesetzt. Dateien, die seither neu hinzugekommen sind (z.B. Uploads), bleiben bestehen und können verwaist (ohne zugehörigen DB-Eintrag) im Webroot zurückbleiben.">
                        <input type="hidden" name="_csrf"       value="<?= Auth::csrfToken() ?>">
                        <input type="hidden" name="_action"     value="restore">
                        <input type="hidden" name="file"        value="<?= htmlspecialchars($name) ?>">
                        <input type="hidden" name="full_clean"  value="0">
                        <button class="btn btn-sm btn-outline-secondary" title="Nur zusammenführen (Merge) — neue Dateien seit dem Backup bleiben bestehen">
                            <i class="bi bi-arrow-counterclockwise"></i><i class="bi bi-union ms-1"></i>
                        </button>
                    </form>
                    <?php endif ?>

                    <!-- Delete -->
                    <form method="post" action="/admin/backup" class="d-inline"
                          data-confirm="Backup wirklich löschen?">
                        <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
                        <input type="hidden" name="_action" value="delete">
                        <input type="hidden" name="file"    value="<?= htmlspecialchars($name) ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Löschen">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body text-center text-secondary py-5">
        Noch keine Backups vorhanden.
    </div>
</div>
<?php endif ?>

<div class="alert alert-secondary mt-4 small">
    <i class="bi bi-arrow-counterclockwise me-2"></i>
    <strong>Wiederherstellung — zwei Modi:</strong>
    <strong><i class="bi bi-arrow-counterclockwise"></i> Vollständig</strong> (empfohlen) setzt Datenbank
    und Dateien exakt auf den Backup-Zeitpunkt zurück — Dateien, die seither neu hinzugekommen sind
    (z.B. Uploads), werden entfernt.
    <strong><i class="bi bi-arrow-counterclockwise"></i><i class="bi bi-union"></i> Merge</strong> setzt
    nur die Datenbank zurück; neue Dateien seit dem Backup bleiben bestehen und können danach verwaist
    (ohne zugehörigen DB-Eintrag, aber weiterhin öffentlich erreichbar) im Webroot zurückbleiben.
</div>

<div class="alert alert-secondary mt-3 small">
    <i class="bi bi-shield-lock me-2"></i>
    <strong>Sicherheitshinweis:</strong>
    Backups enthalten den vollständigen Datenbankinhalt inkl. verschlüsselter Passwörter und SMTP-Zugangsdaten.
    Downloads werden über eine gesicherte Route ausgeliefert und sind nicht direkt per URL erreichbar.
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
