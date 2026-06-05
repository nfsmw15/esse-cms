<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;

if (!Auth::check()) {
    http_response_code(403);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=3600');

$ts       = DB::table('settings');
$packName = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'icon_pack'") ?? 'bootstrap-icons';
$packDir  = ESSE_ROOT . '/public/vendor/' . basename($packName);
$packJson = $packDir . '/iconpack.json';

if (!file_exists($packJson)) {
    echo json_encode(['prefix' => 'bi bi-', 'icons' => []]);
    exit;
}

$meta    = json_decode(file_get_contents($packJson), true);
$prefix  = $meta['prefix'] ?? 'bi bi-';
$cssFile = $packDir . '/' . ltrim($meta['css'] ?? '', '/');

if (!file_exists($cssFile)) {
    echo json_encode(['prefix' => $prefix, 'icons' => []]);
    exit;
}

$css = file_get_contents($cssFile);

// Derive the CSS selector prefix from the last space-separated token of the pack prefix.
// e.g. "bi bi-" → "bi-",  "fa-solid fa-" → "fa-"
$parts     = explode(' ', trim($prefix));
$cssPrefix = end($parts);

preg_match_all(
    '/\.' . preg_quote($cssPrefix, '/') . '([a-z0-9][a-z0-9-]*)::?before/',
    $css,
    $matches
);

$icons = array_values(array_unique($matches[1]));
sort($icons);

echo json_encode(['prefix' => $prefix, 'icons' => $icons]);
