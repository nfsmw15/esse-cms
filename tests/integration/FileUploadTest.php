<?php

declare(strict_types=1);

// Erzeugt eine kleine valide PNG-Datei und gibt den Pfad zurueck.
function makeTestPng(): string
{
    $path = tempnam(sys_get_temp_dir(), 'esse-test-img-') . '.png';
    $img  = imagecreatetruecolor(2, 2);
    imagepng($img, $path);
    imagedestroy($img);
    return $path;
}

// Erzeugt eine Textdatei (kein Bild), getarnt mit der angegebenen Endung.
function makeFakeFile(string $extension): string
{
    $path = tempnam(sys_get_temp_dir(), 'esse-test-file-') . '.' . $extension;
    file_put_contents($path, "<?php echo 'pwned'; ?>\n");
    return $path;
}

return [
    'POST /admin/files/upload: Gast wird zu /login umgeleitet' => function (Http $http) {
        $res = $http->post('/admin/files/upload', []);

        Assert::same(302, $res['status']);
        $location = $res['headers']['location'][0] ?? '';
        Assert::true(str_starts_with($location, '/login'), "Redirect zu /login erwartet, war: {$location}");
    },

    'POST /admin/files/upload: Member ohne manage_files/manage_content erhaelt 403' => function (Http $http) {
        loginAs($http, TEST_MEMBER_EMAIL, TEST_MEMBER_PASSWORD);

        $res = $http->post('/admin/files/upload', []);
        Assert::same(403, $res['status']);
    },

    'POST /admin/files/upload: ohne CSRF-Token wird abgelehnt' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);

        $res = $http->post('/admin/files/upload', []);
        Assert::same(403, $res['status']);
    },

    'POST /admin/files/upload: PHP-Datei mit .jpg-Endung wird abgelehnt' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $csrf = extractCsrf($http->get('/admin/pages')['body']);

        $fake = makeFakeFile('jpg');
        $res  = $http->postMultipart('/admin/files/upload', ['_csrf' => $csrf], [
            'file' => ['path' => $fake, 'name' => 'evil.jpg', 'type' => 'image/jpeg'],
        ]);
        @unlink($fake);

        Assert::same(200, $res['status']);
        $data = json_decode($res['body'], true);
        Assert::true(isset($data['error']), 'Fehlermeldung fuer ungueltige Bilddatei erwartet');
    },

    'POST /admin/files/upload: PHP-Datei mit .php-Endung wird abgelehnt' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $csrf = extractCsrf($http->get('/admin/pages')['body']);

        $fake = makeFakeFile('php');
        $res  = $http->postMultipart('/admin/files/upload', ['_csrf' => $csrf], [
            'file' => ['path' => $fake, 'name' => 'evil.php', 'type' => 'application/x-php'],
        ]);
        @unlink($fake);

        Assert::same(200, $res['status']);
        $data = json_decode($res['body'], true);
        Assert::true(isset($data['error']), 'Fehlermeldung fuer Dateityp .php erwartet');
        Assert::true(str_contains($data['error'], 'Dateityp nicht erlaubt'), 'Meldung "Dateityp nicht erlaubt" erwartet');
    },

    'POST /admin/files/upload: gueltiges PNG wird angenommen' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $csrf = extractCsrf($http->get('/admin/pages')['body']);

        $png = makeTestPng();
        $res = $http->postMultipart('/admin/files/upload', ['_csrf' => $csrf], [
            'file' => ['path' => $png, 'name' => 'photo.png', 'type' => 'image/png'],
        ]);
        @unlink($png);

        Assert::same(200, $res['status']);
        $data = json_decode($res['body'], true);
        Assert::true(isset($data['url']) && str_starts_with($data['url'], '/public/uploads/'), 'Erfolgreiche Upload-URL erwartet');

        // Aufraeumen: hochgeladene Datei wieder entfernen.
        $uploaded = dirname(__DIR__, 2) . $data['url'];
        @unlink($uploaded);
    },
];
