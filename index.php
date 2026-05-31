<?php

declare(strict_types=1);

define('ESSE_VERSION', '0.1.0-dev');
define('ESSE_ROOT', __DIR__);

// local.php can define ESSE_PRIVATE_PATH to point outside the webroot (recommended for VPS/HestiaCP).
// Example local.php: define('ESSE_PRIVATE_PATH', '/home/user/private/esse');
// local.php is gitignored and never committed.
if (file_exists(ESSE_ROOT . '/local.php')) {
    require_once ESSE_ROOT . '/local.php';
}
defined('ESSE_PRIVATE_PATH') || define('ESSE_PRIVATE_PATH', ESSE_ROOT);

// Autoloader: Esse\Router -> core/Router.php, Esse\Hooks -> core/Hooks.php etc.
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Esse\\')) return;
    $path = ESSE_ROOT . '/core/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    if (file_exists($path)) require_once $path;
});

use Esse\Hooks;
use Esse\Router;

// Redirect to installer if config is missing
$configFile = ESSE_PRIVATE_PATH . '/config/config.php';
if (!file_exists($configFile)) {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (!str_starts_with(rtrim($uri ?? '/', '/'), '/install')) {
        header('Location: /install/');
        exit;
    }
} else {
    require_once $configFile;
}

// Plugins register their routes here before dispatch
Hooks::fire('router.boot');

// Core routes
require_once ESSE_ROOT . '/core/routes.php';

// Dispatch
Router::dispatch();
