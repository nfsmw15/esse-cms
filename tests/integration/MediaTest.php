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

    'POST /admin/media (_action=upload, visibility=private): Datei liegt ausserhalb des Webroots, nicht per direkter URL erreichbar' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $csrf = extractCsrf($http->get('/admin/media')['body']);

        $txt = tempnam(sys_get_temp_dir(), 'esse-test-private-') . '.txt';
        file_put_contents($txt, 'geheimer inhalt');

        $upload = $http->postMultipart('/admin/media', ['_csrf' => $csrf, '_action' => 'upload', 'visibility' => 'private'], [
            'file' => ['path' => $txt, 'name' => 'private-test.txt', 'type' => 'text/plain'],
        ]);
        @unlink($txt);
        Assert::same(302, $upload['status']);

        $list = json_decode($http->get('/admin/media/list?visibility=private')['body'], true);
        $item = null;
        foreach ($list['items'] as $i) {
            if ($i['filename'] === 'private-test.txt') { $item = $i; break; }
        }
        Assert::true($item !== null, 'Private Datei sollte in der Mediathek-Liste auftauchen');

        try {
            // Die alte oeffentliche Konvention (/public/uploads/<name>) darf es fuer diese Datei
            // gar nicht geben - sie liegt jetzt ausserhalb des per HTTP erreichbaren Docroots.
            Assert::true(!str_starts_with($item['url'], '/public/'), "Private Datei sollte keine /public/-URL haben, war: {$item['url']}");
            Assert::true(!is_file(dirname(__DIR__, 2) . '/public/uploads/private-test.txt'), 'Datei darf physisch nicht unter public/uploads liegen');

            $viaEndpoint = $http->get($item['url']); // /admin/media/file/{id}, eingeloggt als Forge
            Assert::same(200, $viaEndpoint['status'], 'Forge sollte die Datei ueber den kontrollierten Endpoint sehen');
            Assert::same('geheimer inhalt', $viaEndpoint['body']);
        } finally {
            $http->post('/admin/media', ['_csrf' => $csrf, '_action' => 'delete', 'id' => (string) $item['id']]);
        }
    },

    'GET /admin/media/file/{id}: Gast wird zu /login umgeleitet, sieht den Inhalt nicht' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $csrf = extractCsrf($http->get('/admin/media')['body']);

        $txt = tempnam(sys_get_temp_dir(), 'esse-test-private-') . '.txt';
        file_put_contents($txt, 'nur fuer angemeldete');
        $upload = $http->postMultipart('/admin/media', ['_csrf' => $csrf, '_action' => 'upload', 'visibility' => 'private'], [
            'file' => ['path' => $txt, 'name' => 'private-guest-test.txt', 'type' => 'text/plain'],
        ]);
        @unlink($txt);

        $list = json_decode($http->get('/admin/media/list?visibility=private')['body'], true);
        $item = null;
        foreach ($list['items'] as $i) {
            if ($i['filename'] === 'private-guest-test.txt') { $item = $i; break; }
        }
        Assert::true($item !== null);

        try {
            $guest = new Http(TEST_BASE_URL); // frischer Client ohne Session-Cookie
            $res = $guest->get($item['url']);
            Assert::same(302, $res['status']);
            $location = $res['headers']['location'][0] ?? '';
            Assert::true(str_starts_with($location, '/login'), "Redirect zu /login erwartet, war: {$location}");
        } finally {
            $http->post('/admin/media', ['_csrf' => $csrf, '_action' => 'delete', 'id' => (string) $item['id']]);
        }
    },

    'POST /admin/media (_action=update, visibility wechselt): Datei wird physisch verschoben' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $csrf = extractCsrf($http->get('/admin/media')['body']);

        // Oeffentlich hochladen, dann auf privat umstellen
        $png = tempnam(sys_get_temp_dir(), 'esse-test-img-') . '.png';
        $img = imagecreatetruecolor(2, 2);
        imagepng($img, $png);
        imagedestroy($img);
        $upload = $http->postMultipart('/admin/files/upload', ['_csrf' => $csrf], [
            'file' => ['path' => $png, 'name' => 'visibility-switch.png', 'type' => 'image/png'],
        ]);
        @unlink($png);
        $uploadData = json_decode($upload['body'], true);
        $publicDisk = dirname(__DIR__, 2) . $uploadData['url'];
        Assert::true(is_file($publicDisk), 'Oeffentlich hochgeladene Datei sollte zunaechst im Webroot liegen');

        $list = json_decode($http->get('/admin/media/list')['body'], true);
        $mediaId = null;
        foreach ($list['items'] as $i) {
            if ($i['url'] === $uploadData['url']) { $mediaId = $i['id']; break; }
        }
        Assert::true($mediaId !== null);

        try {
            // public -> private: Datei muss aus dem Webroot verschwinden
            $http->post('/admin/media', [
                '_csrf' => $csrf, '_action' => 'update', 'id' => (string) $mediaId, 'visibility' => 'private',
            ]);
            clearstatcache(true, $publicDisk);
            Assert::true(!is_file($publicDisk), 'Nach dem Wechsel auf "privat" darf die Datei nicht mehr im Webroot liegen');

            $afterPrivate = json_decode($http->get('/admin/media/list')['body'], true);
            $privateUrl = null;
            foreach ($afterPrivate['items'] as $i) {
                if ($i['id'] === $mediaId) { $privateUrl = $i['url']; break; }
            }
            Assert::true(str_starts_with($privateUrl, '/admin/media/file/'), "Private URL sollte ueber den kontrollierten Endpoint laufen, war: {$privateUrl}");
            Assert::same(200, $http->get($privateUrl)['status']);

            // private -> public: Datei muss wieder im Webroot landen
            $http->post('/admin/media', [
                '_csrf' => $csrf, '_action' => 'update', 'id' => (string) $mediaId, 'visibility' => 'public',
            ]);
            $afterPublic = json_decode($http->get('/admin/media/list')['body'], true);
            $publicUrl = null;
            foreach ($afterPublic['items'] as $i) {
                if ($i['id'] === $mediaId) { $publicUrl = $i['url']; break; }
            }
            Assert::true(str_starts_with($publicUrl, '/public/'), "Oeffentliche URL erwartet, war: {$publicUrl}");
            $newPublicDisk = dirname(__DIR__, 2) . $publicUrl;
            Assert::true(is_file($newPublicDisk), 'Nach dem Wechsel auf "oeffentlich" sollte die Datei wieder im Webroot liegen');
            @unlink($newPublicDisk);
        } finally {
            $http->post('/admin/media', ['_csrf' => $csrf, '_action' => 'delete', 'id' => (string) $mediaId]);
            @unlink($publicDisk);
        }
    },

    'POST /admin/media (_action=delete) entfernt auch die Datei vom Server' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $csrf = extractCsrf($http->get('/admin/media')['body']);

        $png = tempnam(sys_get_temp_dir(), 'esse-test-img-') . '.png';
        $img = imagecreatetruecolor(2, 2);
        imagepng($img, $png);
        imagedestroy($img);

        $upload = $http->postMultipart('/admin/files/upload', ['_csrf' => $csrf], [
            'file' => ['path' => $png, 'name' => 'mediadeletetest.png', 'type' => 'image/png'],
        ]);
        @unlink($png);
        $uploadData = json_decode($upload['body'], true);
        $diskPath   = dirname(__DIR__, 2) . $uploadData['url'];
        Assert::true(is_file($diskPath), 'Hochgeladene Datei sollte auf dem Server liegen');

        $list = json_decode($http->get('/admin/media/list')['body'], true);
        $mediaId = null;
        foreach ($list['items'] as $item) {
            if ($item['url'] === $uploadData['url']) { $mediaId = $item['id']; break; }
        }
        Assert::true($mediaId !== null, 'Hochgeladene Datei sollte in der Mediathek-Liste auftauchen');

        $http->post('/admin/media', ['_csrf' => $csrf, '_action' => 'delete', 'id' => (string) $mediaId]);

        // Die Datei wird vom php -S-Serverprozess geloescht, nicht vom Testrunner-Prozess —
        // ohne clearstatcache() wuerde is_file() hier den gecachten Stat von oben zurueckgeben.
        clearstatcache(true, $diskPath);
        Assert::true(!is_file($diskPath), 'Datei sollte nach Mediathek-Löschen nicht mehr auf dem Server liegen');

        @unlink($diskPath); // Sicherheitsnetz, falls der Test fehlschlägt
    },
];
