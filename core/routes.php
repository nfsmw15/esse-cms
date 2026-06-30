<?php

declare(strict_types=1);

use Esse\Router;

// -- Frontend --

Router::get('/', function () {
    $ts   = \Esse\DB::table('settings');
    $rows = array_column(
        \Esse\DB::fetchAll("SELECT `key`, `value` FROM `{$ts}` WHERE `key` IN ('homepage_slug', 'login_homepage_slug')"),
        'value', 'key'
    );

    // Logged-in users get the configured post-login homepage (fallback: general homepage)
    $slug = (\Esse\Auth::check() && !empty($rows['login_homepage_slug']))
        ? $rows['login_homepage_slug']
        : ($rows['homepage_slug'] ?? null);

    if ($slug) {
        $slug = (string) $slug;
        $bare = ltrim($slug, '/');
        $tp   = \Esse\DB::table('pages');
        $isCmsPage = (bool) \Esse\DB::value(
            "SELECT 1 FROM `{$tp}` WHERE `slug` = ? AND `status` = 'published'",
            [$bare]
        );

        if ($isCmsPage) {
            // Render CMS pages directly so the URL stays '/'
            \Esse\PageRenderer::render($bare);
        } else {
            // Standard pages (login, profil, …), plugin pages, etc. live at their own route
            header('Location: ' . \Esse\PageTargets::redirectUrl($slug, '/'));
            exit;
        }
    } else {
        echo '<p class="m-4">ESSE CMS — Startseite nicht konfiguriert. '
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

Router::get('/passwort-vergessen', fn() => require ESSE_ROOT . '/admin/forgot-password.php', [
    'name' => 'forgot_password',
    'auth' => 'public',
]);
Router::post('/passwort-vergessen', fn() => require ESSE_ROOT . '/admin/forgot-password.php', [
    'name' => 'forgot_password.post',
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

Router::get('/neues-passwort', fn() => require ESSE_ROOT . '/admin/reset-password.php', [
    'name' => 'reset_password',
    'auth' => 'public',
]);
Router::post('/neues-passwort', fn() => require ESSE_ROOT . '/admin/reset-password.php', [
    'name' => 'reset_password.post',
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

Router::get('/email-bestaetigen', fn() => require ESSE_ROOT . '/admin/verify-email.php', [
    'name' => 'verify_email',
    'auth' => 'public',
]);
Router::post('/email-bestaetigen', fn() => require ESSE_ROOT . '/admin/verify-email.php', [
    'name' => 'verify_email.post',
    'auth' => 'public',
]);

Router::get('/admin/verify-email', fn() => require ESSE_ROOT . '/admin/verify-email.php', [
    'name' => 'admin.verify_email',
    'auth' => 'public',
]);
Router::post('/admin/verify-email', fn() => require ESSE_ROOT . '/admin/verify-email.php', [
    'name' => 'admin.verify_email.post',
    'auth' => 'public',
]);

// Klassisches 2FA-Gate (TOTP + Backup-Codes) — Zwischenschritt nach korrektem Passwort
Router::get('/admin/verify-2fa', fn() => require ESSE_ROOT . '/admin/verify-2fa.php', [
    'name' => 'admin.verify_2fa',
    'auth' => 'public',
]);
Router::post('/admin/verify-2fa', fn() => require ESSE_ROOT . '/admin/verify-2fa.php', [
    'name' => 'admin.verify_2fa.post',
    'auth' => 'public',
]);

// -- Passkey/WebAuthn JSON-Endpunkte (Muster siehe admin/files-upload.php) --
// 'auth' => 'public': Registrierung wird intern per Auth::check() abgesichert (Nutzer muss
// eingeloggt sein, um einen Passkey hinzuzufügen); die passwortlose Anmeldung kennt den Nutzer
// naturgemäß noch nicht — Identifikation läuft erst über die zurückgegebene credential_id.

Router::post('/admin/passkey/register-options', function () {
    header('Content-Type: application/json');
    if (!\Esse\Auth::check())      { http_response_code(403); echo json_encode(['error' => 'Nicht eingeloggt.']); return; }
    if (!\Esse\Auth::verifyCsrf()) { http_response_code(403); echo json_encode(['error' => 'Ungültige Anfrage.']); return; }

    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    if (!\Esse\Auth::verifyCurrentPassword((string) ($body['confirm_password'] ?? ''))) {
        http_response_code(403); echo json_encode(['error' => 'Passwort falsch.']); return;
    }
    try {
        echo json_encode(\Esse\WebAuthn::registrationOptions(\Esse\Auth::user()));
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Passkey-Registrierung momentan nicht möglich.']);
    }
}, ['name' => 'admin.passkey.register_options', 'auth' => 'public']);

Router::post('/admin/passkey/register-verify', function () {
    header('Content-Type: application/json');
    if (!\Esse\Auth::check())      { http_response_code(403); echo json_encode(['error' => 'Nicht eingeloggt.']); return; }
    if (!\Esse\Auth::verifyCsrf()) { http_response_code(403); echo json_encode(['error' => 'Ungültige Anfrage.']); return; }

    $body       = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $label      = trim((string) ($body['label'] ?? ''));
    $credential = $body['credential'] ?? null;
    if (!is_array($credential)) { echo json_encode(['error' => 'Ungültige Antwort des Browsers.']); return; }

    try {
        \Esse\WebAuthn::verifyRegistration(\Esse\Auth::user(), $credential, $label);
        \Esse\AuditLog::record('passkey_added', \Esse\Auth::id(), \Esse\Auth::user()['email'] ?? null);
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        echo json_encode(['error' => 'Registrierung fehlgeschlagen. Bitte erneut versuchen.']);
    }
}, ['name' => 'admin.passkey.register_verify', 'auth' => 'public']);

Router::post('/admin/passkey/auth-options', function () {
    header('Content-Type: application/json');
    if (!\Esse\Auth::verifyCsrf()) { http_response_code(403); echo json_encode(['error' => 'Ungültige Anfrage.']); return; }

    // Gleicher Bucket wie auth-verify — beide Endpunkte gehoeren zur selben Anmelde-Ceremony,
    // wiederholte Fehlversuche ueber auth-verify bremsen damit auch neue auth-options-Anfragen
    // von derselben IP aus.
    $bucket = 'passkey_login:' . \Esse\RateLimit::clientIp();
    if (\Esse\RateLimit::tooMany($bucket, 10, 600)) {
        http_response_code(429); echo json_encode(['error' => 'Zu viele Versuche. Bitte kurz warten.']); return;
    }

    try {
        echo json_encode(\Esse\WebAuthn::passwordlessAuthOptions());
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Passkey-Anmeldung momentan nicht möglich.']);
    }
}, ['name' => 'admin.passkey.auth_options', 'auth' => 'public']);

Router::post('/admin/passkey/auth-verify', function () {
    header('Content-Type: application/json');
    if (!\Esse\Auth::verifyCsrf()) { http_response_code(403); echo json_encode(['error' => 'Ungültige Anfrage.']); return; }

    // Ohne Bremse liesse sich diese Route beliebig oft mit ungueltigen Credential-Daten
    // aufrufen — kein Account-Takeover (Signatur-Pruefung schlaegt fehl), aber Audit-Log-/
    // DB-Last durch automatisierte Fehlversuche. Bei Limit weder DB-Lookup noch Log-Eintrag,
    // damit das Spammen selbst kein zusaetzliches Log-Spam erzeugt.
    $bucket = 'passkey_login:' . \Esse\RateLimit::clientIp();
    if (\Esse\RateLimit::tooMany($bucket, 10, 600)) {
        http_response_code(429); echo json_encode(['error' => 'Zu viele Versuche. Bitte kurz warten.']); return;
    }

    $body       = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $credential = $body['credential'] ?? null;
    if (!is_array($credential)) {
        \Esse\RateLimit::hit($bucket);
        echo json_encode(['error' => 'Ungültige Antwort des Browsers.']); return;
    }

    $user = \Esse\WebAuthn::verifyPasswordlessAuth($credential);
    if (!$user) {
        \Esse\RateLimit::hit($bucket);
        \Esse\AuditLog::record('passkey_login_failed', null, null, ['credential_id' => $credential['id'] ?? null]);
        echo json_encode(['error' => 'Passkey-Anmeldung fehlgeschlagen.']); return;
    }

    \Esse\RateLimit::clear($bucket);
    \Esse\Auth::login($user);
    \Esse\AuditLog::record('login_success', (int) $user['id'], $user['email']);

    $redirect = trim((string) ($body['redirect'] ?? ''));
    $target   = ($redirect !== '' && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//'))
        ? $redirect
        : null;
    if (!$target) {
        $ts   = \Esse\DB::table('settings');
        $slug = \Esse\DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'login_homepage_slug'") ?: '/';
        $target = \Esse\PageTargets::redirectUrl((string) $slug, '/');
    }
    echo json_encode(['ok' => true, 'redirect' => $target]);
}, ['name' => 'admin.passkey.auth_verify', 'auth' => 'public']);

// Umbenennen/Entfernen laufen über den bestehenden POST-Handler von /profil
// (Aktionen 'passkey_rename' / 'passkey_remove') — kein eigenes JSON-Routing nötig.

Router::post('/admin/settings/test-mail', function () {
    if (!\Esse\Auth::verifyCsrf()) { http_response_code(403); exit; }
    if (!\Esse\Auth::can('manage_settings')) { http_response_code(403); exit; }
    try {
        \Esse\Mailer::test();
        $ts = \Esse\DB::table('settings');
        $to = \Esse\DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'admin_email'")
            ?: \Esse\Auth::user()['email'];
        \Esse\Mailer::send($to, 'Admin', 'ESSE CMS Test-Mail', '<p>SMTP funktioniert.</p>');
        \Esse\Flash::set('success', 'Test-Mail erfolgreich gesendet.');
    } catch (\Throwable $e) {
        \Esse\Flash::set('danger', 'SMTP-Fehler: ' . $e->getMessage());
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
        \Esse\AuditLog::record('page_deleted', \Esse\Auth::id(), \Esse\Auth::user()['email'] ?? null, ['page_id' => $page['id'], 'slug' => $slug, 'title' => $page['title']]);
        \Esse\Flash::set('success', "Seite '{$page['title']}' gelöscht.");
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

Router::post('/admin/users', fn() => require ESSE_ROOT . '/admin/users/list.php', [
    'name' => 'admin.users.post',
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

Router::get('/admin/logs', fn() => require ESSE_ROOT . '/admin/logs.php', [
    'name' => 'admin.logs',
    'auth' => 'view_logs',
]);

Router::get('/admin/themes', fn() => require ESSE_ROOT . '/admin/themes/index.php', [
    'name' => 'admin.themes',
    'auth' => 'manage_themes',
]);

Router::post('/admin/themes', fn() => require ESSE_ROOT . '/admin/themes/index.php', [
    'name' => 'admin.themes.post',
    'auth' => 'manage_themes',
]);

Router::get('/admin/repos', fn() => require ESSE_ROOT . '/admin/repos.php', [
    'name' => 'admin.repos',
    'auth' => 'manage_repos',
]);

Router::post('/admin/repos', fn() => require ESSE_ROOT . '/admin/repos.php', [
    'name' => 'admin.repos.post',
    'auth' => 'manage_repos',
]);

Router::get('/admin/settings', fn() => require ESSE_ROOT . '/admin/settings.php', [
    'name' => 'admin.settings',
    'auth' => 'manage_settings',
]);

Router::post('/admin/settings', fn() => require ESSE_ROOT . '/admin/settings.php', [
    'name' => 'admin.settings.post',
    'auth' => 'manage_settings',
]);

Router::get('/admin/media', fn() => require ESSE_ROOT . '/admin/media.php', [
    'name' => 'admin.media',
    'auth' => ['manage_files', 'manage_content'],
]);

Router::post('/admin/media', fn() => require ESSE_ROOT . '/admin/media.php', [
    'name' => 'admin.media.post',
    'auth' => ['manage_files', 'manage_content'],
]);

Router::get('/admin/media/list', fn() => require ESSE_ROOT . '/admin/media-list.php', [
    'name' => 'admin.media.list',
    'auth' => ['manage_files', 'manage_content'],
]);

Router::get('/admin/media/file/{id}', function (string $id) {
    $idParam = $id;
    require ESSE_ROOT . '/admin/media-file.php';
}, ['name' => 'admin.media.file', 'auth' => ['manage_files', 'manage_content']]);

Router::get('/admin/shortcodes/list', fn() => require ESSE_ROOT . '/admin/shortcodes-list.php', [
    'name' => 'admin.shortcodes.list',
    'auth' => 'manage_content',
]);

Router::get('/admin/user-fields', fn() => require ESSE_ROOT . '/admin/user-fields.php', [
    'name' => 'admin.user_fields',
    'auth' => 'manage_settings',
]);

Router::post('/admin/user-fields', fn() => require ESSE_ROOT . '/admin/user-fields.php', [
    'name' => 'admin.user_fields.post',
    'auth' => 'manage_settings',
]);

// 'auth' hier nur die erste Huerde — die Handler selbst prüfen die tatsächlich erforderlichen
// (engeren) Forge-only-Permissions manage_backups/manage_updates. War zuvor auf das viel breiter
// vergebene manage_settings gesetzt; nicht direkt ausnutzbar (Handler blockten korrekt), aber
// inkonsistent und ein Risiko, falls der Handler-Check je entfernt/abgeschwächt wird.
Router::get('/admin/backup', fn() => require ESSE_ROOT . '/admin/backup.php', [
    'name' => 'admin.backup',
    'auth' => 'manage_backups',
]);

Router::post('/admin/backup', fn() => require ESSE_ROOT . '/admin/backup.php', [
    'name' => 'admin.backup.post',
    'auth' => 'manage_backups',
]);

Router::get('/admin/backup/download/{file}', function (string $file) {
    $fileParam = $file;
    require ESSE_ROOT . '/admin/backup-download.php';
}, ['name' => 'admin.backup.download', 'auth' => 'manage_backups']);

Router::get('/admin/update', fn() => require ESSE_ROOT . '/admin/updater.php', [
    'name' => 'admin.update',
    'auth' => ['manage_updates', 'manage_backups'],
]);

Router::post('/admin/update', fn() => require ESSE_ROOT . '/admin/updater.php', [
    'name' => 'admin.update.post',
    'auth' => ['manage_updates', 'manage_backups'],
]);

Router::get('/admin/update/run', fn() => require ESSE_ROOT . '/admin/updater-run.php', [
    'name' => 'admin.update.run',
    'auth' => ['manage_updates', 'manage_backups'],
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

// -- SEO --

Router::get('/robots.txt', function () {
    $ts = \Esse\DB::table('settings');
    $settings = array_column(\Esse\DB::fetchAll("SELECT `key`, `value` FROM `{$ts}`"), 'value', 'key');

    header('Content-Type: text/plain; charset=utf-8');
    echo \Esse\Seo::robotsTxt($settings);
}, ['name' => 'robots', 'auth' => 'public']);

Router::get('/sitemap.xml', function () {
    $ts = \Esse\DB::table('settings');
    $settings = array_column(\Esse\DB::fetchAll("SELECT `key`, `value` FROM `{$ts}`"), 'value', 'key');

    if (($settings['seo_sitemap_enabled'] ?? '0') !== '1') {
        \Esse\Router::abort(404);
        return;
    }

    header('Content-Type: application/xml; charset=utf-8');
    echo \Esse\Seo::sitemapXml($settings);
}, ['name' => 'sitemap', 'auth' => 'public']);

// -- Frontend pages (must be last — catches any unmatched slug) --

Router::get('/{slug}', fn(string $slug) => \Esse\PageRenderer::render($slug), [
    'name' => 'page.show',
    'auth' => 'public',
]);
