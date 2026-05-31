<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\Updater;

// Only forge/admin with manage_settings can run updates
if (!Auth::meetsRole('forge') && !Auth::can('manage_settings')) {
    http_response_code(403); exit;
}

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

set_time_limit(0);
ignore_user_abort(true);

// Flush output immediately
while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

function sse(string $message, string $type = 'info'): void
{
    echo 'data: ' . json_encode(['type' => $type, 'message' => $message]) . "\n\n";
    flush();
}

try {
    // 1. Get latest release info
    sse('Prüfe auf verfügbare Updates...');
    $info = Updater::checkForUpdate();

    if (!$info) {
        sse('GitHub nicht erreichbar.', 'error');
        exit;
    }

    if (!Updater::isNewer($info['version'], ESSE_VERSION)) {
        sse('Kein Update verfügbar — bereits aktuell.', 'info');
        sse('done', 'done');
        exit;
    }

    sse("Update auf v{$info['version']} wird gestartet...");

    // 2. Backup
    sse('─── Schritt 1: Backup ───');
    Updater::createBackup(fn($msg) => sse($msg));
    sse('Backup abgeschlossen.', 'success');

    // 3. Download
    sse('─── Schritt 2: Download ───');
    $zipPath = Updater::download($info['download_url'], fn($msg) => sse($msg));
    sse('Download abgeschlossen.', 'success');

    // 4. Apply
    sse('─── Schritt 3: Update anwenden ───');
    Updater::apply($zipPath, fn($msg) => sse($msg));
    sse('Dateien aktualisiert.', 'success');

    // 5. Clear update cache
    $cache = ESSE_PRIVATE_PATH . '/storage/cache/update_check.json';
    @unlink($cache);

    sse("─────────────────────────────────");
    sse("ESSE CMS v{$info['version']} wurde erfolgreich installiert.", 'success');
    sse('Seite wird neu geladen...', 'info');
    sse('done', 'done');

} catch (\Throwable $e) {
    sse('Fehler: ' . $e->getMessage(), 'error');
    sse('Update abgebrochen. Das Backup liegt in storage/backups/.', 'error');
}
