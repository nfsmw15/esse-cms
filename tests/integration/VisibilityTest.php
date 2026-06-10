<?php

declare(strict_types=1);

return [
    'GET /profil: Gast wird zu /login umgeleitet (visibility=registered)' => function (Http $http) {
        $res = $http->get('/profil');

        Assert::same(302, $res['status']);
        $location = $res['headers']['location'][0] ?? '';
        Assert::true(str_starts_with($location, '/login'), "Redirect zu /login erwartet, war: {$location}");
    },

    'GET /profil: eingeloggter Benutzer erhaelt 200' => function (Http $http) {
        loginAs($http, TEST_MEMBER_EMAIL, TEST_MEMBER_PASSWORD);

        $res = $http->get('/profil');
        Assert::same(200, $res['status']);
    },

    'GET /registrieren: Gast erhaelt 200 (visibility=guest_only)' => function (Http $http) {
        $res = $http->get('/registrieren');
        Assert::same(200, $res['status']);
    },

    'GET /registrieren: eingeloggter Benutzer wird zu / umgeleitet' => function (Http $http) {
        loginAs($http, TEST_MEMBER_EMAIL, TEST_MEMBER_PASSWORD);

        $res = $http->get('/registrieren');
        Assert::same(302, $res['status']);
        Assert::same('/', $res['headers']['location'][0] ?? null);
    },
];
