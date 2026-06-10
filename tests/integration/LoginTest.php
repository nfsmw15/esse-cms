<?php

declare(strict_types=1);

return [
    'POST /login: falsches Passwort wird abgelehnt' => function (Http $http) {
        $page = $http->get('/login');
        $csrf = extractCsrf($page['body']);

        $res = $http->post('/login', [
            '_csrf'    => $csrf,
            '_form'    => 'admin_login',
            'login'    => TEST_FORGE_EMAIL,
            'password' => 'falsches-passwort',
        ]);

        Assert::same(200, $res['status']);
        Assert::true(str_contains($res['body'], 'falsch'), 'Fehlermeldung "falsch" erwartet');
    },

    'POST /login: 5 Fehlversuche sperren fuer 60s' => function (Http $http) {
        $page = $http->get('/login');
        $csrf = extractCsrf($page['body']);

        for ($i = 0; $i < 5; $i++) {
            $http->post('/login', [
                '_csrf'    => $csrf,
                '_form'    => 'admin_login',
                'login'    => TEST_FORGE_EMAIL,
                'password' => 'falsch',
            ]);
        }

        $res = $http->post('/login', [
            '_csrf'    => $csrf,
            '_form'    => 'admin_login',
            'login'    => TEST_FORGE_EMAIL,
            'password' => 'falsch',
        ]);

        Assert::true(str_contains($res['body'], 'Fehlversuche'), 'Sperr-Meldung nach 5 Fehlversuchen erwartet');
    },

    'POST /login: korrekte Zugangsdaten fuehren zu Redirect' => function (Http $http) {
        $page = $http->get('/login');
        $csrf = extractCsrf($page['body']);

        $res = $http->post('/login', [
            '_csrf'    => $csrf,
            '_form'    => 'admin_login',
            'login'    => TEST_MEMBER_EMAIL,
            'password' => TEST_MEMBER_PASSWORD,
        ]);

        Assert::same(302, $res['status']);
    },

    'POST /login: ohne CSRF-Token wird abgelehnt' => function (Http $http) {
        $res = $http->post('/login', [
            '_form'    => 'admin_login',
            'login'    => TEST_MEMBER_EMAIL,
            'password' => TEST_MEMBER_PASSWORD,
        ]);

        Assert::same(200, $res['status']);
        Assert::true(str_contains($res['body'], 'Ungültige Anfrage'), 'CSRF-Fehlermeldung erwartet');
    },

    'POST /abmelden: ohne CSRF-Token wird mit 403 abgelehnt' => function (Http $http) {
        $res = $http->post('/abmelden', []);
        Assert::same(403, $res['status']);
    },
];
