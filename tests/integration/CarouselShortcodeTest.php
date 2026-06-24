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

    'CoreShortcodes::renderCarousel(): private Bilder bekommen den berechtigungsgeprueften Endpoint statt des internen Pfads' => function (Http $http) {
        $tm = DB::table('media');

        $privateId = DB::insert($tm, [
            'path' => '/private-media/carousel-private-test.jpg', 'filename' => 'private.jpg',
            'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 1, 'visibility' => 'private', 'source' => 'core',
        ]);

        try {
            $html = CoreShortcodes::renderCarousel(['images' => (string) $privateId]);

            // Der interne Pfad/Dateiname darf nicht ins HTML gelangen - das war der eigentliche
            // Leak. Stattdessen steht der opake, berechtigungsgeprüfte Endpoint drin.
            Assert::true(!str_contains($html, '/private-media/'), 'Interner privater Pfad darf nicht ins gerenderte HTML gelangen');
            Assert::true(!str_contains($html, 'carousel-private-test.jpg'), 'Privater Dateiname darf nicht ins gerenderte HTML gelangen');
            Assert::true(str_contains($html, "/admin/media/file/{$privateId}"), 'Privates Bild sollte ueber den kontrollierten Endpoint eingebunden werden');

            // Der Endpoint selbst entscheidet dann pro Besucher (beim Laden des <img>-Tags im
            // Browser), ob das Bild angezeigt wird - bereits in MediaTest.php abgedeckt
            // (Gast wird zu /login umgeleitet, berechtigter Nutzer sieht den Inhalt).
        } finally {
            DB::delete($tm, ['id' => $privateId]);
        }
    },

    'CoreShortcodes::renderCarousel(): liefert leeren String ohne gueltige Bild-IDs' => function (Http $http) {
        Assert::same('', CoreShortcodes::renderCarousel(['images' => '']));
        Assert::same('', CoreShortcodes::renderCarousel(['images' => '999999999']));
    },
];
