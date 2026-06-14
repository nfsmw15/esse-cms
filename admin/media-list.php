<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\Media;

if (!Auth::canAny(['manage_files', 'manage_content'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Keine Berechtigung.']);
    exit;
}

header('Content-Type: application/json');

$filters = array_filter([
    'type'       => $_GET['type'] ?? '',
    'visibility' => $_GET['visibility'] ?? '',
    'q'          => trim($_GET['q'] ?? ''),
]);

$items = array_map(static function (array $item): array {
    return [
        'id'         => (int) $item['id'],
        'url'        => $item['path'],
        'filename'   => $item['filename'],
        'type'       => $item['type'],
        'alt'        => $item['alt_text'],
        'visibility' => $item['visibility'],
    ];
}, Media::list($filters));

echo json_encode(['items' => $items]);
