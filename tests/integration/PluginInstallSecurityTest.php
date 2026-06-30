<?php

declare(strict_types=1);

use Esse\DB;

// Baut ein minimal gueltiges Plugin-ZIP fuer Upload-Tests.
function makeTestPluginZip(string $slug): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-plugin-') . '.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString("{$slug}/plugin.json", json_encode(['name' => $slug, 'version' => '1.0.0']));
    $zip->addFromString("{$slug}/Plugin.php", '<?php class ' . str_replace('-', '', $slug) . ' {}');
    $zip->close();
    return $zipPath;
}

function removePluginDirIfExists(string $slug): void
{
    $dir = dirname(__DIR__, 2) . '/plugins/' . $slug;
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

return [
    'POST /admin/plugins (_action=upload): Admin mit manage_plugins ohne Forge erhaelt 403' => function (Http $http) {
        $tu    = DB::table('users');
        $email = 'plugin-admin-' . bin2hex(random_bytes(4)) . '@example.test';
        $pass  = 'Plugin-Admin-Pass1';
        $userId = DB::insert($tu, [
            'display_name'      => 'Plugin Admin Test',
            'email'             => $email,
            'password'          => password_hash($pass, PASSWORD_BCRYPT),
            'role'              => 'admin',
            'active'            => 1,
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);

        $slug = 'test-plugin-' . bin2hex(random_bytes(3));
        try {
            loginAs($http, $email, $pass);
            $csrf = extractCsrf($http->get('/admin/plugins')['body']);

            $zipPath = makeTestPluginZip($slug);
            $res = $http->postMultipart('/admin/plugins', ['_csrf' => $csrf, '_action' => 'upload'], [
                'plugin_zip' => ['path' => $zipPath, 'name' => "{$slug}.zip", 'type' => 'application/zip'],
            ]);
            @unlink($zipPath);

            Assert::same(403, $res['status']);
            Assert::true(!is_dir(dirname(__DIR__, 2) . '/plugins/' . $slug), 'Plugin darf trotz 403 nicht installiert worden sein');
        } finally {
            DB::delete($tu, ['id' => $userId]);
            removePluginDirIfExists($slug);
        }
    },

    'POST /admin/plugins (_action=upload): Forge darf weiterhin per ZIP installieren' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $csrf = extractCsrf($http->get('/admin/plugins')['body']);

        $slug = 'test-plugin-' . bin2hex(random_bytes(3));
        $zipPath = makeTestPluginZip($slug);
        try {
            $res = $http->postMultipart('/admin/plugins', ['_csrf' => $csrf, '_action' => 'upload'], [
                'plugin_zip' => ['path' => $zipPath, 'name' => "{$slug}.zip", 'type' => 'application/zip'],
            ]);
            Assert::same(302, $res['status']);
            Assert::true(is_dir(dirname(__DIR__, 2) . '/plugins/' . $slug), 'Plugin sollte fuer Forge installiert worden sein');
        } finally {
            @unlink($zipPath);
            removePluginDirIfExists($slug);
        }
    },
];
