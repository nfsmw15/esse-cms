<?php

declare(strict_types=1);

use Esse\DB;

function makeTempAdmin(string $rolePrefix = 'admin-test'): array
{
    $tu    = DB::table('users');
    $email = $rolePrefix . '-' . bin2hex(random_bytes(4)) . '@example.test';
    $pass  = 'Temp-Admin-Pass1';
    $id    = DB::insert($tu, [
        'display_name' => 'Temp Admin',
        'email'        => $email,
        'password'     => password_hash($pass, PASSWORD_BCRYPT),
        'role'         => 'admin',
        'active'       => 1,
    ]);
    return ['id' => $id, 'email' => $email, 'password' => $pass];
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

    'POST /admin/plugins (_action=add_repo): Admin ohne manage_repos erhaelt 403' => function (Http $http) {
        $tu   = DB::table('users');
        $user = makeTempAdmin('repo-admin');

        try {
            loginAs($http, $user['email'], $user['password']);
            $csrf = extractCsrf($http->get('/admin/plugins?tab=available')['body']);

            $res = $http->post('/admin/plugins', [
                '_csrf' => $csrf, '_action' => 'add_repo',
                'repo_owner' => 'some-dummy-owner', 'repo_label' => 'Dummy',
            ]);

            Assert::same(403, $res['status']);

            $tr = DB::table('plugin_repos');
            Assert::true(DB::fetch("SELECT id FROM `{$tr}` WHERE owner = ?", ['some-dummy-owner']) === null, 'Kanal darf trotz 403 nicht angelegt worden sein');
        } finally {
            DB::delete($tu, ['id' => $user['id']]);
        }
    },

    'POST /admin/plugins (_action=add_repo): Forge darf weiterhin Kanaele verwalten' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $csrf = extractCsrf($http->get('/admin/plugins?tab=available')['body']);

        $owner = 'dummy-forge-owner-' . bin2hex(random_bytes(3));
        $tr = DB::table('plugin_repos');
        try {
            $res = $http->post('/admin/plugins', [
                '_csrf' => $csrf, '_action' => 'add_repo',
                'repo_owner' => $owner, 'repo_label' => 'Dummy',
            ]);
            Assert::same(302, $res['status']);
            $repo = DB::fetch("SELECT * FROM `{$tr}` WHERE owner = ?", [$owner]);
            Assert::true($repo !== null, 'Kanal sollte fuer Forge angelegt worden sein');

            $csrf2 = extractCsrf($http->get('/admin/plugins?tab=available')['body']);
            $http->post('/admin/plugins', ['_csrf' => $csrf2, '_action' => 'remove_repo', 'repo_id' => (string) $repo['id']]);
            Assert::true(DB::fetch("SELECT id FROM `{$tr}` WHERE owner = ?", [$owner]) === null, 'Kanal sollte wieder entfernt worden sein');
        } finally {
            DB::delete($tr, ['owner' => $owner]);
        }
    },
];
