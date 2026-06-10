<?php

declare(strict_types=1);

return [
    'GET /admin/pages: Gast wird zu /login umgeleitet' => function (Http $http) {
        $res = $http->get('/admin/pages');

        Assert::same(302, $res['status']);
        $location = $res['headers']['location'][0] ?? '';
        Assert::true(str_starts_with($location, '/login'), "Redirect zu /login erwartet, war: {$location}");
    },

    'GET /admin/pages: Member ohne manage_content erhaelt 403' => function (Http $http) {
        loginAs($http, TEST_MEMBER_EMAIL, TEST_MEMBER_PASSWORD);

        $res = $http->get('/admin/pages');
        Assert::same(403, $res['status']);
    },

    'GET /admin/pages: Forge erhaelt 200' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);

        $res = $http->get('/admin/pages');
        Assert::same(200, $res['status']);
    },

    'GET /admin/users: Member ohne manage_users erhaelt 403' => function (Http $http) {
        loginAs($http, TEST_MEMBER_EMAIL, TEST_MEMBER_PASSWORD);

        $res = $http->get('/admin/users');
        Assert::same(403, $res['status']);
    },

    'GET /admin: Member ohne Admin-Berechtigungen erhaelt 403' => function (Http $http) {
        loginAs($http, TEST_MEMBER_EMAIL, TEST_MEMBER_PASSWORD);

        $res = $http->get('/admin');
        Assert::same(403, $res['status']);
    },

    'GET /admin: Forge erhaelt 200' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);

        $res = $http->get('/admin');
        Assert::same(200, $res['status']);
    },
];
