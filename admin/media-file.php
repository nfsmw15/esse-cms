<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\Media;

// Gleiches Berechtigungs-Gate wie /admin/media — wer private Dateien in der Mediathek sehen
// darf, darf sie auch ausliefern lassen. Es gibt keine feinere Eigentümer-Berechtigung im
// aktuellen Modell.
if (!Auth::canAny(['manage_files', 'manage_content'])) {
    http_response_code(403); exit;
}

$id    = (int) ($idParam ?? 0); // injected by route
$media = $id > 0 ? Media::find($id) : null;

if (!$media) {
    http_response_code(404); exit;
}

$abs = Media::absolutePath($media['path']);
if (!$abs || !is_file($abs)) {
    http_response_code(404); exit;
}

header('Content-Type: ' . ($media['mime_type'] ?: 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . basename($media['filename']) . '"');
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

readfile($abs);
