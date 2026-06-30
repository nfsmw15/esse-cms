<?php

declare(strict_types=1);

use Esse\DB;

function makeTempAdmin(string $rolePrefix = 'admin-test'): array
{
    $tu    = DB::table('users');
    $email = $rolePrefix . '-' . bin2hex(random_bytes(4)) . '@example.test';
    $pass  = 'Temp-Admin-Pass1';
    $id    = DB::insert($tu, [
        'display_name'      => 'Temp Admin',
        'email'             => $email,
        'password'          => password_hash($pass, PASSWORD_BCRYPT),
        'role'              => 'admin',
        'active'            => 1,
        'email_verified_at' => date('Y-m-d H:i:s'),
    ]);
    return ['id' => $id, 'email' => $email, 'password' => $pass];
}

function grantPermission(int $userId, string $slug): void
{
    $tup = DB::table('user_permissions');
    DB::insert($tup, ['user_id' => $userId, 'permission_slug' => $slug, 'granted' => 1]);
}

return [
    'POST /admin/iconpacks (_action=upload): Admin mit manage_settings ohne Forge erhaelt 403' => function (Http $http) {
        $tu   = DB::table('users');
        $user = makeTempAdmin('iconpack-admin');

        try {
            loginAs($http, $user['email'], $user['password']);
            $csrf = extractCsrf($http->get('/admin/iconpacks')['body']);

            $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-iconpack-') . '.zip';
            $zip = new ZipArchive();
            $zip->open($zipPath, ZipArchive::CREATE);
            $zip->addFromString('test-pack/iconpack.json', json_encode(['name' => 'test-pack', 'version' => '1.0.0', 'css' => 'x.css']));
            $zip->addFromString('test-pack/x.css', '.x{}');
            $zip->close();

            $res = $http->postMultipart('/admin/iconpacks', ['_csrf' => $csrf, '_action' => 'upload'], [
                'pack_zip' => ['path' => $zipPath, 'name' => 'test-pack.zip', 'type' => 'application/zip'],
            ]);
            @unlink($zipPath);

            Assert::same(403, $res['status']);
            Assert::true(!is_dir(dirname(__DIR__, 2) . '/public/vendor/test-pack'), 'Icon-Pack darf trotz 403 nicht installiert worden sein');
        } finally {
            DB::delete($tu, ['id' => $user['id']]);
        }
    },

    'GET /admin/iconpacks?tab=available: laedt fehlerfrei (Channel-Suche ueber repo_channels)' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $res = $http->get('/admin/iconpacks?tab=available');
        Assert::same(200, $res['status']);
        Assert::true(str_contains($res['body'], 'esse-iconpack'), 'Seite sollte auf das Topic esse-iconpack verweisen');
    },

    'GET/POST /admin/repos: Admin ohne manage_repos erhaelt 403' => function (Http $http) {
        $tu   = DB::table('users');
        $user = makeTempAdmin('repo-noperm-admin');

        try {
            loginAs($http, $user['email'], $user['password']);

            $getRes = $http->get('/admin/repos');
            Assert::same(403, $getRes['status']);

            $csrf = extractCsrf($http->get('/admin/plugins')['body']);
            $postRes = $http->post('/admin/repos', [
                '_csrf' => $csrf, '_action' => 'add_repo',
                'repo_owner' => 'some-dummy-owner', 'repo_label' => 'Dummy',
            ]);
            Assert::same(403, $postRes['status']);

            $tr = DB::table('repo_channels');
            Assert::true(DB::fetch("SELECT id FROM `{$tr}` WHERE owner = ?", ['some-dummy-owner']) === null, 'Kanal darf trotz 403 nicht angelegt worden sein');
        } finally {
            DB::delete($tu, ['id' => $user['id']]);
        }
    },

    'POST /admin/repos (_action=toggle_trust): Admin mit manage_repos aber ohne Forge erhaelt 403 + Audit-Event' => function (Http $http) {
        $tu   = DB::table('users');
        $tl   = DB::table('audit_log');
        $tr   = DB::table('repo_channels');
        $user = makeTempAdmin('repo-manage-admin');
        grantPermission($user['id'], 'manage_repos');
        DB::query("DELETE FROM `{$tl}` WHERE event = 'repo_action_forbidden'");

        try {
            loginAs($http, $user['email'], $user['password']);
            $csrf = extractCsrf($http->get('/admin/repos')['body']);

            $official = DB::fetch("SELECT * FROM `{$tr}` WHERE owner = 'nfsmw15'");
            Assert::true($official !== null, 'Offizieller Kanal sollte per Migration existieren');

            $res = $http->post('/admin/repos', [
                '_csrf' => $csrf, '_action' => 'toggle_trust', 'repo_id' => (string) $official['id'],
            ]);
            Assert::same(403, $res['status']);

            $stillTrusted = DB::value("SELECT trusted FROM `{$tr}` WHERE id = ?", [$official['id']]);
            Assert::same(1, (int) $stillTrusted, 'Vertrauensstufe darf sich trotz 403 nicht geaendert haben');

            $row = DB::fetch("SELECT * FROM `{$tl}` WHERE event = 'repo_action_forbidden' ORDER BY id DESC LIMIT 1");
            Assert::true($row !== null, 'Es sollte ein repo_action_forbidden-Eintrag existieren');
            Assert::same($user['email'], $row['email'] ?? null);
        } finally {
            DB::delete($tu, ['id' => $user['id']]);
        }
    },

    'POST /admin/repos (_action=add_repo/remove_repo): Admin mit manage_repos kann Kanal verwalten' => function (Http $http) {
        $tu    = DB::table('users');
        $tr    = DB::table('repo_channels');
        $user  = makeTempAdmin('repo-crud-admin');
        $owner = 'dummy-admin-owner-' . bin2hex(random_bytes(3));
        grantPermission($user['id'], 'manage_repos');

        try {
            loginAs($http, $user['email'], $user['password']);
            $csrf = extractCsrf($http->get('/admin/repos')['body']);

            $res = $http->post('/admin/repos', [
                '_csrf' => $csrf, '_action' => 'add_repo',
                'repo_owner' => $owner, 'repo_label' => 'Dummy',
            ]);
            Assert::same(302, $res['status']);
            $repo = DB::fetch("SELECT * FROM `{$tr}` WHERE owner = ?", [$owner]);
            Assert::true($repo !== null, 'Kanal sollte angelegt worden sein');
            Assert::same(0, (int) $repo['trusted'], 'Neu angelegter Kanal muss unverifiziert sein');

            $csrf2 = extractCsrf($http->get('/admin/repos')['body']);
            $http->post('/admin/repos', ['_csrf' => $csrf2, '_action' => 'remove_repo', 'repo_id' => (string) $repo['id']]);
            Assert::true(DB::fetch("SELECT id FROM `{$tr}` WHERE owner = ?", [$owner]) === null, 'Kanal sollte wieder entfernt worden sein');
        } finally {
            DB::delete($tr, ['owner' => $owner]);
            DB::delete($tu, ['id' => $user['id']]);
        }
    },

    'POST /admin/repos (_action=toggle_trust): Forge darf Vertrauensstufe aendern' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $tr    = DB::table('repo_channels');
        $owner = 'dummy-forge-owner-' . bin2hex(random_bytes(3));

        try {
            $csrf = extractCsrf($http->get('/admin/repos')['body']);
            $http->post('/admin/repos', ['_csrf' => $csrf, '_action' => 'add_repo', 'repo_owner' => $owner, 'repo_label' => 'Dummy']);
            $repo = DB::fetch("SELECT * FROM `{$tr}` WHERE owner = ?", [$owner]);
            Assert::true($repo !== null);

            $csrf2 = extractCsrf($http->get('/admin/repos')['body']);
            $res = $http->post('/admin/repos', ['_csrf' => $csrf2, '_action' => 'toggle_trust', 'repo_id' => (string) $repo['id']]);
            Assert::same(302, $res['status']);

            $trusted = DB::value("SELECT trusted FROM `{$tr}` WHERE id = ?", [$repo['id']]);
            Assert::same(1, (int) $trusted, 'Forge sollte die Vertrauensstufe auf vertrauenswuerdig setzen koennen');
        } finally {
            DB::delete($tr, ['owner' => $owner]);
        }
    },

    'GET /admin/plugins?tab=available: "Kanaele verwalten"-Link nur fuer Forge/manage_repos sichtbar' => function (Http $http) {
        $tu   = DB::table('users');
        $user = makeTempAdmin('repo-ui-admin');

        try {
            loginAs($http, $user['email'], $user['password']);
            $body = $http->get('/admin/plugins?tab=available')['body'];
            Assert::true(!str_contains($body, 'href="/admin/repos"'), 'Link sollte fuer Admin ohne manage_repos nicht sichtbar sein');
        } finally {
            DB::delete($tu, ['id' => $user['id']]);
        }

        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $body = $http->get('/admin/plugins?tab=available')['body'];
        Assert::true(str_contains($body, 'href="/admin/repos"'), 'Link sollte fuer Forge sichtbar sein');
    },

    'POST /admin/plugins (_action=add_repo): veraltete Aktion gibt 403 statt stillem 200' => function (Http $http) {
        $tu   = DB::table('users');
        $tl   = DB::table('audit_log');
        $tr   = DB::table('repo_channels');
        DB::query("DELETE FROM `{$tl}` WHERE event = 'repo_action_forbidden'");
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $csrf = extractCsrf($http->get('/admin/plugins')['body']);

        $res = $http->post('/admin/plugins', [
            '_csrf' => $csrf, '_action' => 'add_repo',
            'repo_owner' => 'should-not-be-added', 'repo_label' => 'Dummy',
        ]);

        Assert::same(403, $res['status'], 'Nicht mehr unterstuetzte Aktion muss klar 403 liefern, nicht still 200');
        Assert::true(DB::fetch("SELECT id FROM `{$tr}` WHERE owner = ?", ['should-not-be-added']) === null, 'Kanal darf nicht angelegt worden sein');

        $row = DB::fetch("SELECT * FROM `{$tl}` WHERE event = 'repo_action_forbidden' ORDER BY id DESC LIMIT 1");
        Assert::true($row !== null, 'Der veraltete add_repo-Versuch sollte trotzdem als repo_action_forbidden geloggt werden');
    },

    'POST /admin/themes (_action=remove_repo): veraltete Aktion gibt 403 statt stillem 200' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $csrf = extractCsrf($http->get('/admin/themes')['body']);
        $res  = $http->post('/admin/themes', ['_csrf' => $csrf, '_action' => 'remove_repo', 'repo_id' => '1']);
        Assert::same(403, $res['status']);
    },

    'POST /admin/iconpacks: voellig unbekannte Aktion gibt 403 statt stillem 200' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $csrf = extractCsrf($http->get('/admin/iconpacks')['body']);
        $res  = $http->post('/admin/iconpacks', ['_csrf' => $csrf, '_action' => 'totally_made_up_action']);
        Assert::same(403, $res['status']);
    },

    // Regression: manage_plugins/manage_themes/manage_repos sind getrennte Berechtigungen.
    // install_from_repo prüfte bisher nur das owner/repo-Format per Regex, nicht ob der Owner
    // ueberhaupt ein konfigurierter Kanal ist — ein Nutzer ohne manage_repos (/admin/repos
    // liefert fuer ihn 403) konnte trotzdem jedes beliebige GitHub-Repo als Installationsquelle
    // angeben. Pruefung: die Aktion muss am Kanal-Check scheitern (channel_not_allowed), bevor
    // ueberhaupt GitHub kontaktiert wird (kein no_release/download_failed im Log).
    'POST /admin/plugins (_action=install_from_repo): Admin mit manage_plugins ohne manage_repos kann keinen unbekannten Kanal installieren' => function (Http $http) {
        $tu   = DB::table('users');
        $tl   = DB::table('audit_log');
        $user = makeTempAdmin('plugin-channel-admin');
        grantPermission($user['id'], 'manage_plugins');

        try {
            loginAs($http, $user['email'], $user['password']);
            Assert::same(403, $http->get('/admin/repos')['status'], 'Testnutzer sollte keinen Zugriff auf die Kanalverwaltung haben');

            $csrf = extractCsrf($http->get('/admin/plugins')['body']);
            $res  = $http->post('/admin/plugins', [
                '_csrf' => $csrf, '_action' => 'install_from_repo',
                'repo_full_name' => 'not-a-channel-probe/no-release-424242',
            ]);
            Assert::same(302, $res['status']);

            $row = DB::fetch("SELECT * FROM `{$tl}` WHERE event = 'plugin_install_failed' ORDER BY id DESC LIMIT 1");
            Assert::true($row !== null, 'Es sollte ein plugin_install_failed-Eintrag existieren');
            Assert::true(str_contains((string) $row['details'], 'channel_not_allowed'), 'Grund sollte channel_not_allowed sein, nicht erst beim GitHub-Aufruf scheitern');
        } finally {
            DB::delete($tu, ['id' => $user['id']]);
        }
    },

    'POST /admin/themes (_action=install_from_repo): Admin mit manage_themes ohne manage_repos kann keinen unbekannten Kanal installieren' => function (Http $http) {
        $tu   = DB::table('users');
        $tl   = DB::table('audit_log');
        $user = makeTempAdmin('theme-channel-admin');
        grantPermission($user['id'], 'manage_themes');

        try {
            loginAs($http, $user['email'], $user['password']);
            Assert::same(403, $http->get('/admin/repos')['status'], 'Testnutzer sollte keinen Zugriff auf die Kanalverwaltung haben');

            $csrf = extractCsrf($http->get('/admin/themes')['body']);
            $res  = $http->post('/admin/themes', [
                '_csrf' => $csrf, '_action' => 'install_from_repo',
                'repo_full_name' => 'not-a-channel-probe/no-release-424242',
            ]);
            Assert::same(302, $res['status']);

            $row = DB::fetch("SELECT * FROM `{$tl}` WHERE event = 'theme_install_failed' ORDER BY id DESC LIMIT 1");
            Assert::true($row !== null, 'Es sollte ein theme_install_failed-Eintrag existieren');
            Assert::true(str_contains((string) $row['details'], 'channel_not_allowed'), 'Grund sollte channel_not_allowed sein, nicht erst beim GitHub-Aufruf scheitern');
        } finally {
            DB::delete($tu, ['id' => $user['id']]);
        }
    },

    'POST /admin/iconpacks (_action=install_from_repo): Admin mit manage_settings ohne manage_repos kann keinen unbekannten Kanal installieren' => function (Http $http) {
        $tu   = DB::table('users');
        $tl   = DB::table('audit_log');
        $user = makeTempAdmin('iconpack-channel-admin');
        grantPermission($user['id'], 'manage_settings');

        try {
            loginAs($http, $user['email'], $user['password']);
            Assert::same(403, $http->get('/admin/repos')['status'], 'Testnutzer sollte keinen Zugriff auf die Kanalverwaltung haben');

            $csrf = extractCsrf($http->get('/admin/iconpacks')['body']);
            $res  = $http->post('/admin/iconpacks', [
                '_csrf' => $csrf, '_action' => 'install_from_repo',
                'repo_full_name' => 'not-a-channel-probe/no-release-424242',
            ]);
            Assert::same(302, $res['status']);

            $row = DB::fetch("SELECT * FROM `{$tl}` WHERE event = 'iconpack_install_failed' ORDER BY id DESC LIMIT 1");
            Assert::true($row !== null, 'Es sollte ein iconpack_install_failed-Eintrag existieren');
            Assert::true(str_contains((string) $row['details'], 'channel_not_allowed'), 'Grund sollte channel_not_allowed sein, nicht erst beim GitHub-Aufruf scheitern');
        } finally {
            DB::delete($tu, ['id' => $user['id']]);
        }
    },

    'POST /admin/plugins (_action=install_from_repo): Kanal aus konfiguriertem (aber inaktivem) Kanal wird ebenfalls abgelehnt' => function (Http $http) {
        $tu    = DB::table('users');
        $tr    = DB::table('repo_channels');
        $tl    = DB::table('audit_log');
        $owner = 'inactive-owner-' . bin2hex(random_bytes(3));
        DB::insert($tr, ['owner' => $owner, 'label' => 'Inactive', 'trusted' => 0, 'active' => 0]);
        $user  = makeTempAdmin('plugin-inactive-admin');
        grantPermission($user['id'], 'manage_plugins');

        try {
            loginAs($http, $user['email'], $user['password']);
            $csrf = extractCsrf($http->get('/admin/plugins')['body']);
            $res  = $http->post('/admin/plugins', [
                '_csrf' => $csrf, '_action' => 'install_from_repo',
                'repo_full_name' => $owner . '/some-repo',
            ]);
            Assert::same(302, $res['status']);

            $row = DB::fetch("SELECT * FROM `{$tl}` WHERE event = 'plugin_install_failed' ORDER BY id DESC LIMIT 1");
            Assert::true($row !== null && str_contains((string) $row['details'], 'channel_not_allowed'), 'Deaktivierter Kanal darf nicht als erlaubt durchgehen');
        } finally {
            DB::delete($tu, ['id' => $user['id']]);
            DB::delete($tr, ['owner' => $owner]);
        }
    },

    // Gegenstueck zu den obigen Tests: ein konfigurierter, aktiver Kanal darf weiterhin
    // funktionieren — der Kanal-Check soll nur unbekannte Owner blocken, nicht legitime
    // Installationen aus dem offiziellen Kanal verhindern. "no-such-repo" existiert nicht unter
    // nfsmw15, das Scheitern muss also am GitHub-Aufruf liegen (no_release/download_failed),
    // nicht am Kanal-Check (channel_not_allowed).
    'POST /admin/plugins (_action=install_from_repo): konfigurierter aktiver Kanal (nfsmw15) wird nicht vom Kanal-Check geblockt' => function (Http $http) {
        $tl = DB::table('audit_log');
        $tr = DB::table('repo_channels');
        Assert::true(DB::fetch("SELECT id FROM `{$tr}` WHERE owner = 'nfsmw15' AND active = 1") !== null, 'Offizieller Kanal sollte aktiv konfiguriert sein');

        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $csrf = extractCsrf($http->get('/admin/plugins')['body']);
        $res  = $http->post('/admin/plugins', [
            '_csrf' => $csrf, '_action' => 'install_from_repo',
            'repo_full_name' => 'nfsmw15/no-such-repo-xyz-12345',
        ]);
        Assert::same(302, $res['status']);

        $row = DB::fetch("SELECT * FROM `{$tl}` WHERE event = 'plugin_install_failed' ORDER BY id DESC LIMIT 1");
        Assert::true($row !== null, 'Es sollte ein plugin_install_failed-Eintrag existieren');
        Assert::true(!str_contains((string) $row['details'], 'channel_not_allowed'), 'Konfigurierter aktiver Kanal darf nicht am Kanal-Check scheitern');
    },
];
