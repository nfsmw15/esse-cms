<?php

declare(strict_types=1);

use Esse\Router;

// -- Frontend --

Router::get('/', function () {
    $ts   = \Esse\DB::table('settings');
    $slug = \Esse\DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'homepage_slug'");
    if ($slug) {
        $slug = (string) $slug;
        if (str_starts_with($slug, '/') || \Esse\Plugin::isPluginSlug($slug)) {
            header('Location: ' . \Esse\PageTargets::redirectUrl($slug, '/'));
            exit;
        }
        \Esse\PageRenderer::render($slug);
    } else {
        echo '<p style="font-family:sans-serif;padding:2rem">ESSE CMS — Startseite nicht konfiguriert. '
           . '<a href="/admin/settings">Einstellungen öffnen</a></p>';
    }
}, ['name' => 'home', 'auth' => 'public']);


// Frontend: profile (default: registered — overridable via admin)
Router::get('/profil', function () {
    \Esse\PageRenderer::renderFile(ESSE_ROOT . '/pages/profil.php', 'Mein Profil', 'registered');
}, ['name' => 'profil', 'auth' => 'public']);

Router::post('/profil', function () {
    \Esse\PageRenderer::renderFile(ESSE_ROOT . '/pages/profil.php', 'Mein Profil', 'registered');
}, ['name' => 'profil.post', 'auth' => 'public']);

// Frontend: registration (default: guest_only — overridable via admin)
Router::get('/registrieren', function () {
    \Esse\PageRenderer::renderFile(ESSE_ROOT . '/pages/registrieren.php', 'Registrieren', 'guest_only');
}, ['name' => 'register', 'auth' => 'public']);

Router::post('/registrieren', function () {
    \Esse\PageRenderer::renderFile(ESSE_ROOT . '/pages/registrieren.php', 'Registrieren', 'guest_only');
}, ['name' => 'register.post', 'auth' => 'public']);

// Frontend: logout (all users)
Router::post('/abmelden', function () {
    if (!\Esse\Auth::verifyCsrf()) { http_response_code(403); exit; }
    \Esse\Auth::logout();
    $ts = \Esse\DB::table('settings');
    $target = \Esse\DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'logout_page_slug'") ?: '/';
    header('Location: ' . \Esse\PageTargets::redirectUrl((string) $target, '/'));
    exit;
}, ['name' => 'logout', 'auth' => 'public']);

// -- Admin --

Router::get('/login', fn() => require ESSE_ROOT . '/admin/login.php', [
    'name' => 'login',
    'auth' => 'public',
]);

Router::post('/login', fn() => require ESSE_ROOT . '/admin/login.php', [
    'name' => 'login.post',
    'auth' => 'public',
]);

// File upload endpoint (used by editor)
Router::post('/admin/files/upload', fn() => require ESSE_ROOT . '/admin/files-upload.php', [
    'name' => 'admin.files.upload',
    'auth' => ['manage_files', 'manage_content'],
]);

Router::get('/admin/login', fn() => require ESSE_ROOT . '/admin/login.php', [
    'name' => 'admin.login',
    'auth' => 'public',
]);

Router::post('/admin/login', fn() => require ESSE_ROOT . '/admin/login.php', [
    'name' => 'admin.login.post',
    'auth' => 'public',
]);

Router::get('/admin/forgot-password', fn() => require ESSE_ROOT . '/admin/forgot-password.php', [
    'name' => 'admin.forgot_password',
    'auth' => 'public',
]);
Router::post('/admin/forgot-password', fn() => require ESSE_ROOT . '/admin/forgot-password.php', [
    'name' => 'admin.forgot_password.post',
    'auth' => 'public',
]);

Router::get('/admin/reset-password', fn() => require ESSE_ROOT . '/admin/reset-password.php', [
    'name' => 'admin.reset_password',
    'auth' => 'public',
]);
Router::post('/admin/reset-password', fn() => require ESSE_ROOT . '/admin/reset-password.php', [
    'name' => 'admin.reset_password.post',
    'auth' => 'public',
]);

Router::post('/admin/settings/test-mail', function () {
    if (!\Esse\Auth::verifyCsrf()) { http_response_code(403); exit; }
    if (!\Esse\Auth::can('manage_settings')) { http_response_code(403); exit; }
    try {
        \Esse\Mailer::test();
        $ts = \Esse\DB::table('settings');
        $to = \Esse\DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'admin_email'")
            ?: \Esse\Auth::user()['email'];
        \Esse\Mailer::send($to, 'Admin', 'ESSE CMS Test-Mail', '<p>SMTP funktioniert.</p>');
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Test-Mail erfolgreich gesendet.'];
    } catch (\Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'SMTP-Fehler: ' . $e->getMessage()];
    }
    header('Location: /admin/settings');
    exit;
}, ['name' => 'admin.settings.test_mail', 'auth' => 'manage_settings']);

Router::post('/admin/logout', function () {
    if (!\Esse\Auth::verifyCsrf()) { http_response_code(403); exit; }
    \Esse\Auth::logout();
    $ts = \Esse\DB::table('settings');
    $target = \Esse\DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'logout_page_slug'") ?: '/login';
    header('Location: ' . \Esse\PageTargets::redirectUrl((string) $target, '/login'));
    exit;
}, ['name' => 'admin.logout', 'auth' => 'public']);

Router::get('/admin', fn() => require ESSE_ROOT . '/admin/dashboard.php', [
    'name' => 'admin.dashboard',
    'auth' => ['manage_content', 'manage_users', 'manage_plugins', 'manage_themes', 'manage_settings'],
]);

Router::get('/admin/pages', fn() => require ESSE_ROOT . '/admin/pages/list.php', [
    'name' => 'admin.pages',
    'auth' => 'manage_content',
]);

Router::post('/admin/pages', fn() => require ESSE_ROOT . '/admin/pages/list.php', [
    'name' => 'admin.pages.post',
    'auth' => 'manage_content',
]);

Router::get('/admin/pages/create', fn() => require ESSE_ROOT . '/admin/pages/form.php', [
    'name' => 'admin.pages.create',
    'auth' => 'manage_content',
]);

Router::post('/admin/pages/create', fn() => require ESSE_ROOT . '/admin/pages/form.php', [
    'name' => 'admin.pages.create.post',
    'auth' => 'manage_content',
]);

Router::get('/admin/pages/edit/{slug}', function (string $slug) {
    $editSlug = $slug;
    require ESSE_ROOT . '/admin/pages/form.php';
}, ['name' => 'admin.pages.edit', 'auth' => 'manage_content']);

Router::post('/admin/pages/edit/{slug}', function (string $slug) {
    $editSlug = $slug;
    require ESSE_ROOT . '/admin/pages/form.php';
}, ['name' => 'admin.pages.edit.post', 'auth' => 'manage_content']);

Router::post('/admin/pages/delete/{slug}', function (string $slug) {
    if (!\Esse\Auth::verifyCsrf()) { http_response_code(403); exit; }

    $t    = \Esse\DB::table('pages');
    $page = \Esse\DB::fetch("SELECT * FROM `{$t}` WHERE slug = ?", [$slug]);

    if ($page) {
        if ($page['file_path']) {
            @unlink(ESSE_ROOT . '/pages/' . basename((string)$page['file_path']));
        }
        \Esse\DB::delete($t, ['id' => $page['id']]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => "Seite '{$page['title']}' gelöscht."];
    }

    header('Location: /admin/pages');
    exit;
}, ['name' => 'admin.pages.delete', 'auth' => 'manage_content']);

Router::get('/admin/menus', fn() => require ESSE_ROOT . '/admin/menus/list.php', [
    'name' => 'admin.menus',
    'auth' => 'manage_content',
]);

Router::post('/admin/menus', fn() => require ESSE_ROOT . '/admin/menus/list.php', [
    'name' => 'admin.menus.post',
    'auth' => 'manage_content',
]);

Router::get('/admin/menus/edit/{id}', function (string $id) {
    $menuId = (int) $id;
    require ESSE_ROOT . '/admin/menus/form.php';
}, ['name' => 'admin.menus.edit', 'auth' => 'manage_content']);

Router::post('/admin/menus/edit/{id}', function (string $id) {
    $menuId = (int) $id;
    require ESSE_ROOT . '/admin/menus/form.php';
}, ['name' => 'admin.menus.edit.post', 'auth' => 'manage_content']);

Router::get('/admin/roles', fn() => require ESSE_ROOT . '/admin/roles.php', [
    'name' => 'admin.roles',
    'auth' => 'manage_admins',
]);

Router::post('/admin/roles', fn() => require ESSE_ROOT . '/admin/roles.php', [
    'name' => 'admin.roles.post',
    'auth' => 'manage_admins',
]);

Router::get('/admin/users', fn() => require ESSE_ROOT . '/admin/users/list.php', [
    'name' => 'admin.users',
    'auth' => 'manage_users',
]);

Router::get('/admin/users/create', fn() => require ESSE_ROOT . '/admin/users/form.php', [
    'name' => 'admin.users.create',
    'auth' => 'manage_users',
]);

Router::post('/admin/users/create', fn() => require ESSE_ROOT . '/admin/users/form.php', [
    'name' => 'admin.users.create.post',
    'auth' => 'manage_users',
]);

Router::get('/admin/users/edit/{id}', function (string $id) {
    $userId = (int) $id;
    require ESSE_ROOT . '/admin/users/form.php';
}, ['name' => 'admin.users.edit', 'auth' => 'manage_users']);

Router::post('/admin/users/edit/{id}', function (string $id) {
    $userId = (int) $id;
    require ESSE_ROOT . '/admin/users/form.php';
}, ['name' => 'admin.users.edit.post', 'auth' => 'manage_users']);

Router::get('/admin/plugins', fn() => require ESSE_ROOT . '/admin/plugins/index.php', [
    'name' => 'admin.plugins',
    'auth' => 'manage_plugins',
]);

Router::post('/admin/plugins', fn() => require ESSE_ROOT . '/admin/plugins/index.php', [
    'name' => 'admin.plugins.post',
    'auth' => 'manage_plugins',
]);

Router::get('/admin/iconpacks/icons', fn() => require ESSE_ROOT . '/admin/iconpacks-icons.php', [
    'name' => 'admin.iconpacks.icons',
    'auth' => 'admin',
]);
Router::get('/admin/iconpacks', fn() => require ESSE_ROOT . '/admin/iconpacks.php', [
    'name' => 'admin.iconpacks',
    'auth' => 'manage_settings',
]);
Router::post('/admin/iconpacks', fn() => require ESSE_ROOT . '/admin/iconpacks.php', [
    'name' => 'admin.iconpacks.post',
    'auth' => 'manage_settings',
]);

Router::get('/admin/themes', fn() => require ESSE_ROOT . '/admin/themes/index.php', [
    'name' => 'admin.themes',
    'auth' => 'manage_themes',
]);

Router::post('/admin/themes', fn() => require ESSE_ROOT . '/admin/themes/index.php', [
    'name' => 'admin.themes.post',
    'auth' => 'manage_themes',
]);

Router::get('/admin/settings', fn() => require ESSE_ROOT . '/admin/settings.php', [
    'name' => 'admin.settings',
    'auth' => 'manage_settings',
]);

Router::post('/admin/settings', fn() => require ESSE_ROOT . '/admin/settings.php', [
    'name' => 'admin.settings.post',
    'auth' => 'manage_settings',
]);

Router::get('/admin/backup', fn() => require ESSE_ROOT . '/admin/backup.php', [
    'name' => 'admin.backup',
    'auth' => 'manage_settings',
]);

Router::post('/admin/backup', fn() => require ESSE_ROOT . '/admin/backup.php', [
    'name' => 'admin.backup.post',
    'auth' => 'manage_settings',
]);

Router::get('/admin/backup/download/{file}', function (string $file) {
    $fileParam = $file;
    require ESSE_ROOT . '/admin/backup-download.php';
}, ['name' => 'admin.backup.download', 'auth' => 'manage_settings']);

Router::get('/admin/update', fn() => require ESSE_ROOT . '/admin/updater.php', [
    'name' => 'admin.update',
    'auth' => 'manage_settings',
]);

Router::post('/admin/update', fn() => require ESSE_ROOT . '/admin/updater.php', [
    'name' => 'admin.update.post',
    'auth' => 'manage_settings',
]);

Router::get('/admin/update/run', fn() => require ESSE_ROOT . '/admin/updater-run.php', [
    'name' => 'admin.update.run',
    'auth' => 'manage_settings',
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
