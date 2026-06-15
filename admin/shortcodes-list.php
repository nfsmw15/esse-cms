<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\Shortcodes;

if (!Auth::can('manage_content')) {
    http_response_code(403);
    echo json_encode(['error' => 'Keine Berechtigung.']);
    exit;
}

header('Content-Type: application/json');

echo json_encode(['items' => Shortcodes::getRegistered()]);
