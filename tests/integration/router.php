<?php

declare(strict_types=1);

// Router-Skript fuer "php -S" (siehe tests/integration/run.php). Bildet die
// .htaccess-Rewrite-Regel der echten Installation nach: existierende Dateien
// (Assets) werden direkt ausgeliefert, alles andere geht durch index.php.
//
// auto_prepend_file wird vom php-built-in-server fuer das Router-Skript
// ignoriert, daher wird prepend.php hier direkt eingebunden.
require __DIR__ . '/prepend.php';

$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$file = $root . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require $root . '/index.php';
