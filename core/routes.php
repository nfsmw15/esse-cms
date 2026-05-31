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

Router::get('/admin', fn() => print('Admin Dashboard'), [
    'name' => 'admin.dashboard',
    'auth' => 'admin',
]);

Router::get('/admin/pages', fn() => print('Admin: Seiten'), [
    'name' => 'admin.pages',
    'auth' => 'admin',
]);

Router::get('/admin/users', fn() => print('Admin: Benutzer'), [
    'name' => 'admin.users',
    'auth' => 'admin',
]);

Router::get('/admin/plugins', fn() => print('Admin: Plugins'), [
    'name' => 'admin.plugins',
    'auth' => 'admin',
]);

Router::get('/admin/themes', fn() => print('Admin: Themes'), [
    'name' => 'admin.themes',
    'auth' => 'admin',
]);

Router::get('/admin/settings', fn() => print('Admin: Einstellungen'), [
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
