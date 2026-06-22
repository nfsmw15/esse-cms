<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;
use Esse\Media;

if (!Auth::canAny(['manage_files', 'manage_content'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Keine Berechtigung.']);
    exit;
}

header('Content-Type: application/json');

if (!Auth::verifyCsrf()) {
    http_response_code(403);
    echo json_encode(['error' => 'Ungültige Anfrage.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file']['tmp_name'])) {
    echo json_encode(['error' => 'Keine Datei empfangen.']);
    exit;
}

$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp']; // SVG removed — XSS risk
$file    = $_FILES['file'];
$ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    AuditLog::record('file_upload_rejected', Auth::id(), Auth::user()['email'] ?? null, ['reason' => 'upload_error', 'filename' => $file['name'] ?? null]);
    echo json_encode(['error' => 'Upload fehlgeschlagen.']);
    exit;
}

if (!in_array($ext, $allowed, true)) {
    AuditLog::record('file_upload_rejected', Auth::id(), Auth::user()['email'] ?? null, ['reason' => 'extension', 'filename' => $file['name'], 'extension' => $ext]);
    echo json_encode(['error' => 'Dateityp nicht erlaubt. Erlaubt: ' . implode(', ', $allowed)]);
    exit;
}

// Validate MIME type
$mime = mime_content_type($file['tmp_name']);
if (!str_starts_with($mime, 'image/')) {
    AuditLog::record('file_upload_rejected', Auth::id(), Auth::user()['email'] ?? null, ['reason' => 'mime', 'filename' => $file['name'], 'mime_type' => $mime]);
    echo json_encode(['error' => 'Ungültiger Dateityp.']);
    exit;
}

if (@getimagesize($file['tmp_name']) === false) {
    AuditLog::record('file_upload_rejected', Auth::id(), Auth::user()['email'] ?? null, ['reason' => 'image_invalid', 'filename' => $file['name']]);
    echo json_encode(['error' => 'Ungültige Bilddatei.']);
    exit;
}

// Max 10 MB
if ($file['size'] > 10 * 1024 * 1024) {
    AuditLog::record('file_upload_rejected', Auth::id(), Auth::user()['email'] ?? null, ['reason' => 'size', 'filename' => $file['name'], 'size' => $file['size']]);
    echo json_encode(['error' => 'Datei zu groß (max. 10 MB).']);
    exit;
}

$uploadDir = ESSE_ROOT . '/public/uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// Sanitize filename + make unique
$baseName = pathinfo($file['name'], PATHINFO_FILENAME);
$baseName = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $baseName);
$baseName = trim($baseName, '-') ?: 'image';
$fileName = $baseName . '_' . uniqid() . '.' . $ext;
$dest     = $uploadDir . $fileName;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    echo json_encode(['error' => 'Speichern fehlgeschlagen.']);
    exit;
}

$path = '/public/uploads/' . $fileName;

$mediaId = Media::register($path, [
    'filename'    => $file['name'],
    'mime_type'   => $mime,
    'size'        => $file['size'],
    'uploaded_by' => Auth::id(),
    'source'      => 'editor',
]);

AuditLog::record('media_uploaded', Auth::id(), Auth::user()['email'] ?? null, [
    'media_id'   => $mediaId,
    'path'       => $path,
    'filename'   => $file['name'],
    'mime_type'  => $mime,
    'size'       => $file['size'],
    'visibility' => 'public',
]);

echo json_encode(['url' => $path]);
