<?php

declare(strict_types=1);

use Esse\Menu;

return [
    'isAllowedUrl: erlaubt relative Pfade, Anker und protokoll-relative URLs' => function () {
        Assert::true(Menu::isAllowedUrl(''));
        Assert::true(Menu::isAllowedUrl('/kontakt'));
        Assert::true(Menu::isAllowedUrl('#anker'));
        Assert::true(Menu::isAllowedUrl('?q=test'));
        Assert::true(Menu::isAllowedUrl('//cdn.example.com/datei.pdf'));
    },

    'isAllowedUrl: erlaubt http, https, mailto, tel' => function () {
        Assert::true(Menu::isAllowedUrl('http://example.com'));
        Assert::true(Menu::isAllowedUrl('https://example.com/seite'));
        Assert::true(Menu::isAllowedUrl('mailto:info@example.com'));
        Assert::true(Menu::isAllowedUrl('tel:+491234567'));
        Assert::true(Menu::isAllowedUrl('HTTPS://example.com')); // Scheme case-insensitiv
    },

    'isAllowedUrl: lehnt javascript: und andere gefaehrliche Schemes ab' => function () {
        Assert::false(Menu::isAllowedUrl('javascript:alert(1)'));
        Assert::false(Menu::isAllowedUrl('JavaScript:alert(1)'));
        Assert::false(Menu::isAllowedUrl('data:text/html,<script>alert(1)</script>'));
        Assert::false(Menu::isAllowedUrl('vbscript:msgbox(1)'));
        Assert::false(Menu::isAllowedUrl('file:///etc/passwd'));
    },

    'itemUrl: liefert "#" statt eines gefaehrlichen Schemes (Verteidigung in der Tiefe fuer Altdaten)' => function () {
        $item = ['type' => 'url', 'url' => 'javascript:alert(1)'];
        Assert::same('#', Menu::itemUrl($item));
    },

    'itemUrl: liefert die gespeicherte URL fuer erlaubte Schemes' => function () {
        $item = ['type' => 'url', 'url' => 'https://example.com'];
        Assert::same('https://example.com', Menu::itemUrl($item));
    },
];
