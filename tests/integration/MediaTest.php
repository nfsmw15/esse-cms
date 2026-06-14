<?php

declare(strict_types=1);

return [
    'GET /admin/media: Gast wird zu /login umgeleitet' => function (Http $http) {
        $res = $http->get('/admin/media');

        Assert::same(302, $res['status']);
        $location = $res['headers']['location'][0] ?? '';
        Assert::true(str_starts_with($location, '/login'), "Redirect zu /login erwartet, war: {$location}");
    },

    'GET /admin/media: Member ohne manage_files/manage_content erhaelt 403' => function (Http $http) {
        loginAs($http, TEST_MEMBER_EMAIL, TEST_MEMBER_PASSWORD);

        $res = $http->get('/admin/media');
        Assert::same(403, $res['status']);
    },

    'GET /admin/media: Forge sieht die Mediathek' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);

        $res = $http->get('/admin/media');
        Assert::same(200, $res['status']);
        Assert::true(str_contains($res['body'], 'Mediathek'), 'Seitentitel "Mediathek" erwartet');
    },

    'Upload via /admin/files/upload registriert die Datei in der Mediathek' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $csrf = extractCsrf($http->get('/admin/pages')['body']);

        $png = tempnam(sys_get_temp_dir(), 'esse-test-img-') . '.png';
        $img = imagecreatetruecolor(2, 2);
        imagepng($img, $png);
        imagedestroy($img);

        $res = $http->postMultipart('/admin/files/upload', ['_csrf' => $csrf], [
            'file' => ['path' => $png, 'name' => 'mediatest.png', 'type' => 'image/png'],
        ]);
        @unlink($png);

        $data = json_decode($res['body'], true);
        Assert::true(isset($data['url']), 'Upload-URL erwartet');

        $list = json_decode($http->get('/admin/media/list')['body'], true);
        $found = false;
        foreach ($list['items'] as $item) {
            if ($item['url'] === $data['url']) { $found = true; break; }
        }
        Assert::true($found, 'Hochgeladene Datei sollte in der Mediathek-Liste auftauchen');

        // Aufraeumen
        $uploaded = dirname(__DIR__, 2) . $data['url'];
        @unlink($uploaded);
    },
];
