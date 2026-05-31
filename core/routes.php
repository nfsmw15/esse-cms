<?php

declare(strict_types=1);

use Esse\Router;

// -- Frontend --

Router::get('/', function () {
    $ts   = \Esse\DB::table('settings');
    $slug = \Esse\DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'homepage_slug'");
    if ($slug) {
        \Esse\PageRenderer::render((string) $slug);
    } else {
        echo '<p style="font-family:sans-serif;padding:2rem">ESSE CMS — Startseite nicht konfiguriert. '
           . '<a href="/admin/settings">Einstellungen öffnen</a></p>';
    }
}, ['name' => 'home', 'auth' => 'public']);


// -- Admin --

Router::get('/admin/login', fn() => require ESSE_ROOT . '/admin/login.php', [
    'name' => 'admin.login',
    'auth' => 'public',
]);

Router::post('/admin/login', fn() => require ESSE_ROOT . '/admin/login.php', [
    'name' => 'admin.login.post',
    'auth' => 'public',
]);

Router::post('/admin/logout', function () {
    if (!\Esse\Auth::verifyCsrf()) { http_response_code(403); exit; }
    \Esse\Auth::logout();
    header('Location: /admin/login');
    exit;
}, ['name' => 'admin.logout', 'auth' => 'public']);

Router::get('/admin', fn() => require ESSE_ROOT . '/admin/dashboard.php', [
    'name' => 'admin.dashboard',
    'auth' => 'admin',
]);

Router::get('/admin/pages', fn() => require ESSE_ROOT . '/admin/pages/list.php', [
    'name' => 'admin.pages',
    'auth' => 'admin',
]);

Router::get('/admin/pages/create', fn() => require ESSE_ROOT . '/admin/pages/form.php', [
    'name' => 'admin.pages.create',
    'auth' => 'admin',
]);

Router::post('/admin/pages/create', fn() => require ESSE_ROOT . '/admin/pages/form.php', [
    'name' => 'admin.pages.create.post',
    'auth' => 'admin',
]);

Router::get('/admin/pages/edit/{slug}', function (string $slug) {
    $editSlug = $slug;
    require ESSE_ROOT . '/admin/pages/form.php';
}, ['name' => 'admin.pages.edit', 'auth' => 'admin']);

Router::post('/admin/pages/edit/{slug}', function (string $slug) {
    $editSlug = $slug;
    require ESSE_ROOT . '/admin/pages/form.php';
}, ['name' => 'admin.pages.edit.post', 'auth' => 'admin']);

Router::post('/admin/pages/delete/{slug}', function (string $slug) {
    if (!\Esse\Auth::verifyCsrf()) { http_response_code(403); exit; }

    $t    = \Esse\DB::table('pages');
    $page = \Esse\DB::fetch("SELECT * FROM `{$t}` WHERE slug = ?", [$slug]);

    if ($page) {
        if ($page['file_path']) {
            @unlink(ESSE_ROOT . '/pages/' . $page['file_path']);
        }
        \Esse\DB::delete($t, ['id' => $page['id']]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => "Seite '{$page['title']}' gelöscht."];
    }

    header('Location: /admin/pages');
    exit;
}, ['name' => 'admin.pages.delete', 'auth' => 'admin']);

Router::get('/admin/menus', fn() => require ESSE_ROOT . '/admin/menus/list.php', [
    'name' => 'admin.menus',
    'auth' => 'admin',
]);

Router::post('/admin/menus', fn() => require ESSE_ROOT . '/admin/menus/list.php', [
    'name' => 'admin.menus.post',
    'auth' => 'admin',
]);

Router::get('/admin/menus/edit/{id}', function (string $id) {
    $menuId = (int) $id;
    require ESSE_ROOT . '/admin/menus/form.php';
}, ['name' => 'admin.menus.edit', 'auth' => 'admin']);

Router::post('/admin/menus/edit/{id}', function (string $id) {
    $menuId = (int) $id;
    require ESSE_ROOT . '/admin/menus/form.php';
}, ['name' => 'admin.menus.edit.post', 'auth' => 'admin']);

Router::get('/admin/users', fn() => require ESSE_ROOT . '/admin/users/list.php', [
    'name' => 'admin.users',
    'auth' => 'admin',
]);

Router::get('/admin/users/create', fn() => require ESSE_ROOT . '/admin/users/form.php', [
    'name' => 'admin.users.create',
    'auth' => 'admin',
]);

Router::post('/admin/users/create', fn() => require ESSE_ROOT . '/admin/users/form.php', [
    'name' => 'admin.users.create.post',
    'auth' => 'admin',
]);

Router::get('/admin/users/edit/{id}', function (string $id) {
    $userId = (int) $id;
    require ESSE_ROOT . '/admin/users/form.php';
}, ['name' => 'admin.users.edit', 'auth' => 'admin']);

Router::post('/admin/users/edit/{id}', function (string $id) {
    $userId = (int) $id;
    require ESSE_ROOT . '/admin/users/form.php';
}, ['name' => 'admin.users.edit.post', 'auth' => 'admin']);

Router::get('/admin/plugins', fn() => print('Admin: Plugins — folgt'), [
    'name' => 'admin.plugins',
    'auth' => 'admin',
]);

Router::get('/admin/themes', fn() => print('Admin: Themes — folgt'), [
    'name' => 'admin.themes',
    'auth' => 'admin',
]);

Router::get('/admin/settings', fn() => require ESSE_ROOT . '/admin/settings.php', [
    'name' => 'admin.settings',
    'auth' => 'admin',
]);

Router::post('/admin/settings', fn() => require ESSE_ROOT . '/admin/settings.php', [
    'name' => 'admin.settings.post',
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

// -- Frontend pages (must be last — catches any unmatched slug) --

Router::get('/{slug}', fn(string $slug) => \Esse\PageRenderer::render($slug), [
    'name' => 'page.show',
    'auth' => 'public',
]);
