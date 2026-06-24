<?php

declare(strict_types=1);

use Esse\DB;

function latestAuditEvent(string $event): ?array
{
    $tl = DB::table('audit_log');
    return DB::fetch("SELECT * FROM `{$tl}` WHERE event = ? ORDER BY id DESC LIMIT 1", [$event]);
}

return [
    'Seite erstellen/bearbeiten/löschen werden geloggt' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $tp   = DB::table('pages');
        $slug = 'audit-test-page-' . bin2hex(random_bytes(3));

        try {
            $csrf = extractCsrf($http->get('/admin/pages/create')['body']);
            $http->post('/admin/pages/create', [
                '_csrf' => $csrf, 'title' => 'Audit Test', 'slug' => $slug,
                'type' => 'standard', 'visibility' => 'public', 'status' => 'published', 'content' => 'x',
            ]);
            $page = DB::fetch("SELECT * FROM `{$tp}` WHERE slug = ?", [$slug]);
            Assert::true($page !== null, 'Seite sollte angelegt worden sein');
            $created = latestAuditEvent('page_created');
            Assert::true($created !== null && str_contains((string) $created['details'], $slug));

            $csrf2 = extractCsrf($http->get("/admin/pages/edit/{$slug}")['body']);
            $http->post("/admin/pages/edit/{$slug}", [
                '_csrf' => $csrf2, 'title' => 'Audit Test Updated', 'slug' => $slug,
                'type' => 'standard', 'visibility' => 'public', 'status' => 'published', 'content' => 'x',
            ]);
            $updated = latestAuditEvent('page_updated');
            Assert::true($updated !== null && str_contains((string) $updated['details'], $slug));

            $csrf3 = extractCsrf($http->get('/admin/pages')['body']);
            $http->post("/admin/pages/delete/{$slug}", ['_csrf' => $csrf3]);
            $deleted = latestAuditEvent('page_deleted');
            Assert::true($deleted !== null && str_contains((string) $deleted['details'], $slug));
        } finally {
            DB::delete($tp, ['slug' => $slug]);
        }
    },

    'Menü erstellen/umbenennen/löschen werden geloggt' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $tm   = DB::table('menus');
        $slug = 'audit-test-menu-' . bin2hex(random_bytes(3));

        try {
            $csrf = extractCsrf($http->get('/admin/menus')['body']);
            $http->post('/admin/menus', ['_csrf' => $csrf, '_action' => 'create_menu', 'name' => 'Audit Test', 'slug' => $slug]);
            $menu = DB::fetch("SELECT * FROM `{$tm}` WHERE slug = ?", [$slug]);
            Assert::true($menu !== null, 'Menü sollte angelegt worden sein');
            $created = latestAuditEvent('menu_created');
            Assert::true($created !== null && str_contains((string) $created['details'], $slug));

            $csrf2 = extractCsrf($http->get("/admin/menus/edit/{$menu['id']}")['body']);
            $http->post("/admin/menus/edit/{$menu['id']}", ['_csrf' => $csrf2, '_action' => 'save_menu', 'name' => 'Audit Test Renamed', 'slug' => $slug]);
            $updated = latestAuditEvent('menu_updated');
            Assert::true($updated !== null && str_contains((string) $updated['details'], $slug));

            $csrf3 = extractCsrf($http->get('/admin/menus')['body']);
            $http->post('/admin/menus', ['_csrf' => $csrf3, '_action' => 'delete_menu', 'menu_id' => (string) $menu['id']]);
            $deleted = latestAuditEvent('menu_deleted');
            Assert::true($deleted !== null && str_contains((string) $deleted['details'], $slug));
        } finally {
            DB::delete($tm, ['slug' => $slug]);
        }
    },

    'Profilfeld erstellen/löschen werden geloggt' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $tuf  = DB::table('user_fields');
        $label = 'Audit Test Feld ' . bin2hex(random_bytes(3));

        $csrf = extractCsrf($http->get('/admin/user-fields')['body']);
        $http->post('/admin/user-fields', ['_csrf' => $csrf, '_action' => 'save', 'id' => '0', 'label' => $label, 'type' => 'text']);
        $field = DB::fetch("SELECT * FROM `{$tuf}` WHERE label = ?", [$label]);
        Assert::true($field !== null, 'Profilfeld sollte angelegt worden sein');
        $created = latestAuditEvent('user_field_created');
        Assert::true($created !== null && str_contains((string) $created['details'], $label));

        $csrf2 = extractCsrf($http->get('/admin/user-fields')['body']);
        $http->post('/admin/user-fields', ['_csrf' => $csrf2, '_action' => 'delete', 'id' => (string) $field['id']]);
        Assert::true(DB::fetch("SELECT id FROM `{$tuf}` WHERE id = ?", [$field['id']]) === null);
        $deleted = latestAuditEvent('user_field_deleted');
        Assert::true($deleted !== null && str_contains((string) $deleted['details'], $label));
    },

    'Medienordner erstellen/umbenennen/löschen werden geloggt' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $tmf  = DB::table('media_folders');
        $name = 'Audit Test Ordner ' . bin2hex(random_bytes(3));

        $csrf = extractCsrf($http->get('/admin/media')['body']);
        $http->post('/admin/media', ['_csrf' => $csrf, '_action' => 'create_folder', 'name' => $name]);
        $folder = DB::fetch("SELECT * FROM `{$tmf}` WHERE name = ?", [$name]);
        Assert::true($folder !== null, 'Ordner sollte angelegt worden sein');
        $created = latestAuditEvent('media_folder_created');
        Assert::true($created !== null && str_contains((string) $created['details'], $name));

        $newName = $name . ' Renamed';
        $csrf2 = extractCsrf($http->get('/admin/media')['body']);
        $http->post('/admin/media', ['_csrf' => $csrf2, '_action' => 'rename_folder', 'id' => (string) $folder['id'], 'name' => $newName]);
        $renamed = latestAuditEvent('media_folder_renamed');
        Assert::true($renamed !== null && str_contains((string) $renamed['details'], $newName));

        $csrf3 = extractCsrf($http->get('/admin/media')['body']);
        $http->post('/admin/media', ['_csrf' => $csrf3, '_action' => 'delete_folder', 'id' => (string) $folder['id']]);
        Assert::true(DB::fetch("SELECT id FROM `{$tmf}` WHERE id = ?", [$folder['id']]) === null);
        $deleted = latestAuditEvent('media_folder_deleted');
        Assert::true($deleted !== null);
    },

    'Backup-Download wird geloggt' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $csrf = extractCsrf($http->get('/admin/backup')['body']);
        $http->post('/admin/backup', ['_csrf' => $csrf, '_action' => 'create']);

        $backupDir = TEST_CONFIG_DIR . '/storage/backups';
        $files = glob($backupDir . '/manual_*.zip') ?: [];
        Assert::true(!empty($files), 'Es sollte ein frisches Backup zum Herunterladen geben');
        rsort($files);
        $file = basename($files[0]);

        try {
            $http->get('/admin/backup/download/' . $file);
            $downloaded = latestAuditEvent('backup_downloaded');
            Assert::true($downloaded !== null && str_contains((string) $downloaded['details'], $file));
        } finally {
            @unlink($backupDir . '/' . $file);
        }
    },

    'Passkey umbenennen wird geloggt' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $tu = DB::table('users');
        $tw = DB::table('webauthn_credentials');
        $user = DB::fetch("SELECT id FROM `{$tu}` WHERE email = ?", [TEST_FORGE_EMAIL]);
        $credId = DB::insert($tw, [
            'user_id' => $user['id'], 'credential_id' => bin2hex(random_bytes(16)),
            'public_key' => 'dummy', 'label' => 'Alter Name',
        ]);

        try {
            $csrf = extractCsrf($http->get('/profil')['body']);
            $http->post('/profil', [
                '_csrf' => $csrf, '_action' => 'passkey_rename',
                'credential_id' => (string) $credId, 'label' => 'Neuer Name',
                'confirm_password' => TEST_FORGE_PASSWORD,
            ]);

            $cred = DB::fetch("SELECT * FROM `{$tw}` WHERE id = ?", [$credId]);
            Assert::same('Neuer Name', $cred['label'] ?? null);
            $renamed = latestAuditEvent('passkey_renamed');
            Assert::true($renamed !== null && str_contains((string) $renamed['details'], 'Neuer Name'));
        } finally {
            DB::delete($tw, ['id' => $credId]);
        }
    },

    'Theme aktivieren und Theme-Menüzuordnung werden geloggt' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $ts = DB::table('settings');
        $previousTheme = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'active_theme'");

        try {
            $csrf = extractCsrf($http->get('/admin/themes')['body']);
            $http->post('/admin/themes', ['_csrf' => $csrf, '_action' => 'activate', 'theme_name' => 'esse-base']);
            $activated = latestAuditEvent('theme_activated');
            Assert::true($activated !== null && str_contains((string) $activated['details'], 'esse-base'));

            $csrf2 = extractCsrf($http->get('/admin/themes')['body']);
            $http->post('/admin/themes', [
                '_csrf' => $csrf2, '_action' => 'save_menus', 'theme_name' => 'esse-base',
                'theme_esse-base_menu_main' => 'main', 'theme_esse-base_menu_footer' => 'footer-changed-' . uniqid(),
            ]);
            $menuChanged = latestAuditEvent('theme_menu_changed');
            Assert::true($menuChanged !== null && str_contains((string) $menuChanged['details'], 'esse-base'));
        } finally {
            if ($previousTheme !== null) {
                DB::query("UPDATE `{$ts}` SET `value` = ? WHERE `key` = 'active_theme'", [$previousTheme]);
            }
        }
    },
];
