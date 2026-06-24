<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;

// Only Forge or users with manage_backups
if (!Auth::meetsRole('forge') && !Auth::can('manage_backups')) {
    http_response_code(403); exit;
}

$file      = basename($fileParam ?? ''); // injected by route
$backupDir = ESSE_PRIVATE_PATH . '/storage/backups';
$path      = $backupDir . '/' . $file;

// Validate: must exist, must be a .zip, must not escape the backup dir
if (!$file || !str_ends_with($file, '.zip') || !file_exists($path)
    || realpath($path) !== realpath($backupDir) . DIRECTORY_SEPARATOR . $file) {
    http_response_code(404);
    echo '404 — Backup nicht gefunden.';
    exit;
}

// Backups enthalten den vollen DB-Dump inkl. Zugangsdaten — wer eines herunterlaedt, sollte
// nachvollziehbar sein, genauso wie backup_created/backup_deleted bereits geloggt werden.
AuditLog::record('backup_downloaded', Auth::id(), Auth::user()['email'] ?? null, ['file' => $file]);

// Stream the file securely
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store, no-cache');
header('X-Content-Type-Options: nosniff');

readfile($path);
