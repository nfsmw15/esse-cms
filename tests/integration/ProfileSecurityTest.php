<?php

declare(strict_types=1);

return [
    'POST /profil (_action=totp_setup_start) ohne Passwort wird abgelehnt' => function (Http $http) {
        loginAs($http, TEST_MEMBER_EMAIL, TEST_MEMBER_PASSWORD);

        $csrf = extractCsrf($http->get('/profil')['body']);
        $res  = $http->post('/profil', [
            '_csrf'            => $csrf,
            '_action'          => 'totp_setup_start',
            'confirm_password' => 'falsches-passwort',
        ]);

        Assert::same(200, $res['status']);
        Assert::true(str_contains($res['body'], 'Passwort falsch'), 'Fehlermeldung "Passwort falsch" erwartet');
        Assert::true(!str_contains($res['body'], 'QR-Code'), 'Ohne korrektes Passwort sollte kein QR-Code angezeigt werden');
    },

    'POST /profil (_action=totp_setup_start) mit korrektem Passwort zeigt QR-Code' => function (Http $http) {
        loginAs($http, TEST_MEMBER_EMAIL, TEST_MEMBER_PASSWORD);

        $csrf = extractCsrf($http->get('/profil')['body']);
        $res  = $http->post('/profil', [
            '_csrf'            => $csrf,
            '_action'          => 'totp_setup_start',
            'confirm_password' => TEST_MEMBER_PASSWORD,
        ]);

        Assert::same(200, $res['status']);
        Assert::true(str_contains($res['body'], 'QR-Code'), 'QR-Code-Anleitung nach korrektem Passwort erwartet');

        // Aufraeumen, damit nachfolgende Tests nicht mit einem haengenden Setup starten.
        $csrf2 = extractCsrf($res['body']);
        $http->post('/profil', ['_csrf' => $csrf2, '_action' => 'totp_setup_cancel']);
    },
];
