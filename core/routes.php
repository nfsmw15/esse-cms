<?php

declare(strict_types=1);

use Esse\Router;

// -- Frontend --

Router::get('/', fn() => print('Homepage — Theme-Rendering folgt'), [
    'name' => 'home',
    'auth' => 'public',
]);

Router::get('/page/{slug}', fn(string $slug) => print("Seite: {$slug}"), [
    'name' => 'page.show',
    'auth' => 'public',
]);

// -- Admin --

Router::get('/admin/login', fn() => require ESSE_ROOT . '/admin/login.php', [
    'name' => 'admin.login',
    'auth' => 'public',
]);

Router::post('/admin/login', fn() => require ESSE_ROOT . '/admin/login.php', [
    'name' => 'admin.login.post',
    'auth' => 'public',
]);

Router::get('/admin/logout', function () {
    \Esse\Auth::logout();
    header('Location: /admin/login');
    exit;
}, ['name' => 'admin.logout', 'auth' => 'public']);

Router::get('/admin', fn() => require ESSE_ROOT . '/admin/dashboard.php', [
    'name' => 'admin.dashboard',
    'auth' => 'admin',
]);

Router::get('/admin/pages', fn() => print('Admin: Seiten — folgt'), [
    'name' => 'admin.pages',
    'auth' => 'admin',
]);

Router::get('/admin/users', fn() => print('Admin: Benutzer — folgt'), [
    'name' => 'admin.users',
    'auth' => 'admin',
]);

Router::get('/admin/plugins', fn() => print('Admin: Plugins — folgt'), [
    'name' => 'admin.plugins',
    'auth' => 'admin',
]);

Router::get('/admin/themes', fn() => print('Admin: Themes — folgt'), [
    'name' => 'admin.themes',
    'auth' => 'admin',
]);

Router::get('/admin/settings', fn() => print('Admin: Einstellungen — folgt'), [
    'name' => 'admin.settings',
    'auth' => 'admin',
]);

// -- Installer --

Router::get('/install', fn() => require ESSE_ROOT . '/install/index.php', [
    'name' => 'install',
    'auth' => 'public',
]);

Router::post('/install', fn() => require ESSE_ROOT . '/install/index.php', [
    'name' => 'install.post',
    'auth' => 'public',
]);
