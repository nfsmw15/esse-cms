<?php

declare(strict_types=1);

use Esse\Auth;

// Only Forge/Admin with manage_settings
if (!Auth::meetsRole('forge') && !Auth::can('manage_settings')) {
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

// Stream the file securely
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store, no-cache');
header('X-Content-Type-Options: nosniff');

readfile($path);
