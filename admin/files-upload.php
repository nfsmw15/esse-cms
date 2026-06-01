<?php

declare(strict_types=1);

use Esse\Auth;

// Only admins with manage_content or manage_files permission
if (!Auth::meetsRole('author')) {
    http_response_code(403);
    echo json_encode(['error' => 'Keine Berechtigung.']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file']['tmp_name'])) {
    echo json_encode(['error' => 'Keine Datei empfangen.']);
    exit;
}

$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
$file    = $_FILES['file'];
$ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowed, true)) {
    echo json_encode(['error' => 'Dateityp nicht erlaubt. Erlaubt: ' . implode(', ', $allowed)]);
    exit;
}

// Validate MIME type for non-SVG
if ($ext !== 'svg') {
    $mime = mime_content_type($file['tmp_name']);
    if (!str_starts_with($mime, 'image/')) {
        echo json_encode(['error' => 'Ungültiger Dateityp.']);
        exit;
    }
}

// Max 10 MB
if ($file['size'] > 10 * 1024 * 1024) {
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

echo json_encode(['url' => '/public/uploads/' . $fileName]);
