<?php

declare(strict_types=1);

use Esse\CoreShortcodes;
use Esse\DB;

return [
    'CoreShortcodes::renderCarousel(): oeffentliche Bilder bekommen den direkten Pfad' => function (Http $http) {
        $tm = DB::table('media');

        $publicId = DB::insert($tm, [
            'path' => '/public/uploads/carousel-public-test.jpg', 'filename' => 'public.jpg',
            'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 1, 'visibility' => 'public', 'source' => 'core',
        ]);

        try {
            $html = CoreShortcodes::renderCarousel(['images' => (string) $publicId]);
            Assert::true(str_contains($html, '/public/uploads/carousel-public-test.jpg'), 'Oeffentliches Bild sollte mit direktem Pfad im Carousel-HTML stehen');
        } finally {
            DB::delete($tm, ['id' => $publicId]);
        }
    },

    'CoreShortcodes::renderCarousel(): liefert leeren String ohne gueltige Bild-IDs' => function (Http $http) {
        Assert::same('', CoreShortcodes::renderCarousel(['images' => '']));
        Assert::same('', CoreShortcodes::renderCarousel(['images' => '999999999']));
    },

    // Direkter Aufruf von renderCarousel() ohne HTTP-Request laeuft im Prozess des Test-Runners,
    // dort ist nie ein Nutzer eingeloggt (Auth::$currentUser bleibt null) - simuliert also genau
    // den "Gast"-Fall. Fuer den "eingeloggt + berechtigt"-Fall braucht es eine echte Seite +
    // einen echten HTTP-Request mit Session, siehe die beiden Tests unten.
    'CoreShortcodes::renderCarousel() ohne eingeloggten Nutzer: privates Bild wird komplett ausgelassen (kein kaputtes Bild)' => function (Http $http) {
        $tm = DB::table('media');
        $privateId = DB::insert($tm, [
            'path' => '/private-media/carousel-private-test.jpg', 'filename' => 'private.jpg',
            'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 1, 'visibility' => 'private', 'source' => 'core',
        ]);

        try {
            $html = CoreShortcodes::renderCarousel(['images' => (string) $privateId]);
            Assert::same('', $html, 'Ohne Berechtigung sollte das Carousel mit nur einem privaten Bild komplett leer bleiben, kein <img>-Tag');
        } finally {
            DB::delete($tm, ['id' => $privateId]);
        }
    },

    'Seite mit [carousel]: Gast sieht das private Bild nicht (kein kaputtes Bild-Icon, keine Pfad-Info)' => function (Http $http) {
        $tm = DB::table('media');
        $tp = DB::table('pages');
        $privateId = DB::insert($tm, [
            'path' => '/private-media/carousel-page-private-test.jpg', 'filename' => 'private.jpg',
            'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 1, 'visibility' => 'private', 'source' => 'core',
        ]);
        $slug = 'audit-carousel-guest-' . bin2hex(random_bytes(3));
        DB::insert($tp, [
            'title' => 'Carousel Test', 'slug' => $slug, 'type' => 'standard',
            'visibility' => 'public', 'status' => 'published', 'content' => "[carousel images=\"{$privateId}\"]",
        ]);

        try {
            $res = $http->get('/' . $slug);
            Assert::same(200, $res['status']);
            Assert::true(!str_contains($res['body'], '/admin/media/file/'), 'Gast sollte keinen Hinweis auf das private Bild im HTML sehen');
            Assert::true(!str_contains($res['body'], 'private-media'), 'Gast sollte den internen Pfad nicht im HTML sehen');
        } finally {
            DB::delete($tp, ['slug' => $slug]);
            DB::delete($tm, ['id' => $privateId]);
        }
    },

    'Seite mit [carousel]: Forge (berechtigt) sieht das private Bild ueber den kontrollierten Endpoint' => function (Http $http) {
        $tm = DB::table('media');
        $tp = DB::table('pages');
        $privateId = DB::insert($tm, [
            'path' => '/private-media/carousel-page-forge-test.jpg', 'filename' => 'private.jpg',
            'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 1, 'visibility' => 'private', 'source' => 'core',
        ]);
        $slug = 'audit-carousel-forge-' . bin2hex(random_bytes(3));
        DB::insert($tp, [
            'title' => 'Carousel Test', 'slug' => $slug, 'type' => 'standard',
            'visibility' => 'public', 'status' => 'published', 'content' => "[carousel images=\"{$privateId}\"]",
        ]);

        try {
            loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
            $res = $http->get('/' . $slug);
            Assert::same(200, $res['status']);
            Assert::true(str_contains($res['body'], "/admin/media/file/{$privateId}"), 'Forge sollte das private Bild ueber den kontrollierten Endpoint eingebunden sehen');
            Assert::true(!str_contains($res['body'], 'private-media'), 'Der interne Pfad darf auch fuer Forge nicht im HTML stehen');
        } finally {
            DB::delete($tp, ['slug' => $slug]);
            DB::delete($tm, ['id' => $privateId]);
        }
    },
];
