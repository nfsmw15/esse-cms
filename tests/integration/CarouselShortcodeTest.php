<?php

declare(strict_types=1);

use Esse\CoreShortcodes;
use Esse\DB;

return [
    'CoreShortcodes::renderCarousel() laesst private Bilder aus, oeffentliche bleiben drin' => function (Http $http) {
        $tm = DB::table('media');

        $publicId  = DB::insert($tm, [
            'path' => '/public/uploads/carousel-public-test.jpg', 'filename' => 'public.jpg',
            'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 1, 'visibility' => 'public', 'source' => 'core',
        ]);
        $privateId = DB::insert($tm, [
            'path' => '/private-media/carousel-private-test.jpg', 'filename' => 'private.jpg',
            'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 1, 'visibility' => 'private', 'source' => 'core',
        ]);

        try {
            $html = CoreShortcodes::renderCarousel(['images' => "{$publicId},{$privateId}"]);

            Assert::true(str_contains($html, '/public/uploads/carousel-public-test.jpg'), 'Oeffentliches Bild sollte im Carousel-HTML stehen');
            Assert::true(!str_contains($html, '/private-media/'), 'Privater Pfad darf nicht ins gerenderte HTML gelangen');
            Assert::true(!str_contains($html, 'carousel-private-test.jpg'), 'Privater Dateiname darf nicht ins gerenderte HTML gelangen');
        } finally {
            DB::delete($tm, ['id' => $publicId]);
            DB::delete($tm, ['id' => $privateId]);
        }
    },

    'CoreShortcodes::renderCarousel() liefert leeren String, wenn nur private Bilder uebergeben werden' => function (Http $http) {
        $tm = DB::table('media');
        $privateId = DB::insert($tm, [
            'path' => '/private-media/only-private-test.jpg', 'filename' => 'private.jpg',
            'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 1, 'visibility' => 'private', 'source' => 'core',
        ]);

        try {
            $html = CoreShortcodes::renderCarousel(['images' => (string) $privateId]);
            Assert::same('', $html);
        } finally {
            DB::delete($tm, ['id' => $privateId]);
        }
    },
];
