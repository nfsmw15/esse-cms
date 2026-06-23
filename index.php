<?php

declare(strict_types=1);

defined('ESSE_GITHUB_REPO') || define('ESSE_GITHUB_REPO', 'nfsmw15/esse-cms');
defined('ESSE_ROOT')        || define('ESSE_ROOT', __DIR__);

// local.php can override ESSE_VERSION (for test servers) and define ESSE_PRIVATE_PATH.
// It is gitignored and never committed.
if (file_exists(ESSE_ROOT . '/local.php')) {
    require_once ESSE_ROOT . '/local.php';
}
defined('ESSE_VERSION')      || define('ESSE_VERSION',     '0.8.8-alpha');
defined('ESSE_PRIVATE_PATH') || define('ESSE_PRIVATE_PATH', ESSE_ROOT);

// Autoloader
spl_autoload_register(function (string $class): void {
    // Esse core: Esse\Router → core/Router.php
    if (str_starts_with($class, 'Esse\\')) {
        $path = ESSE_ROOT . '/core/' . str_replace('\\', '/', substr($class, 5)) . '.php';
        if (file_exists($path)) require_once $path;
        return;
    }
    // PHPMailer: PHPMailer\PHPMailer\PHPMailer → vendor/phpmailer/src/PHPMailer.php
    if (str_starts_with($class, 'PHPMailer\\PHPMailer\\')) {
        $name = substr($class, strlen('PHPMailer\\PHPMailer\\'));
        $path = ESSE_ROOT . '/vendor/phpmailer/src/' . $name . '.php';
        if (file_exists($path)) require_once $path;
        return;
    }
    // report-uri/passkeys-php: ReportUri\Passkeys\Binary\ByteBuffer → vendor/webauthn/src/Binary/ByteBuffer.php
    if (str_starts_with($class, 'ReportUri\\Passkeys\\')) {
        $name = substr($class, strlen('ReportUri\\Passkeys\\'));
        $path = ESSE_ROOT . '/vendor/webauthn/src/' . str_replace('\\', '/', $name) . '.php';
        if (file_exists($path)) require_once $path;
    }
});

use Esse\Auth;
use Esse\Hooks;
use Esse\Router;
use Esse\SecurityHeaders;

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

// Browser hardening headers must be sent before the session starts or output begins.
SecurityHeaders::send();

// Start session and load current user
Auth::init();

// Load active theme
if (file_exists($configFile)) {
    $ts          = \Esse\DB::table('settings');
    $activeTheme = \Esse\DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'active_theme'");
    if ($activeTheme) {
        $themeDir  = ESSE_ROOT . '/themes/' . basename($activeTheme);
        $themeFile = $themeDir . '/Theme.php';
        $themeMeta = $themeDir . '/theme.json';
        if (file_exists($themeFile) && file_exists($themeMeta)) {
            require_once $themeFile;
            $themeMeta = json_decode(file_get_contents($themeMeta), true);
            $themeClass = $themeMeta['class'] ?? null;
            if ($themeClass && class_exists($themeClass)) {
                (new $themeClass())->boot();
            }
        }
    }
}

// Core (theme-/plugin-unabhängige) eingebaute Shortcodes/Widgets — vor den Plugins
// registriert, damit ein Plugin einen gleichnamigen Tag bei Bedarf überschreiben kann.
if (file_exists($configFile)) {
    \Esse\CoreShortcodes::boot();
}

// Load enabled plugins
if (file_exists($configFile)) {
    $ts      = \Esse\DB::table('settings');
    $enabled = json_decode(
        \Esse\DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'enabled_plugins'") ?? '[]',
        true
    ) ?: [];
    foreach ($enabled as $slug) {
        $slug       = basename((string) $slug); // no path traversal
        $pluginFile = ESSE_ROOT . '/plugins/' . $slug . '/Plugin.php';
        $metaFile   = ESSE_ROOT . '/plugins/' . $slug . '/plugin.json';
        if (!file_exists($pluginFile) || !file_exists($metaFile)) continue;
        require_once $pluginFile;
        $meta  = json_decode(file_get_contents($metaFile), true);
        $class = $meta['class'] ?? null;
        if ($class && class_exists($class)) {
            (new $class())->boot();
        }
    }
}

// Plugins register their routes here before dispatch
Hooks::fire('router.boot');

// Core routes
require_once ESSE_ROOT . '/core/routes.php';

// Dispatch
Router::dispatch();
