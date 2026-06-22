<?php

declare(strict_types=1);

use Esse\DB;

return [
    'GET /admin/logs: Member ohne view_logs erhaelt 403' => function (Http $http) {
        loginAs($http, TEST_MEMBER_EMAIL, TEST_MEMBER_PASSWORD);

        $res = $http->get('/admin/logs');
        Assert::same(403, $res['status']);
    },

    'GET /admin/logs: Forge erhaelt 200' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);

        $res = $http->get('/admin/logs');
        Assert::same(200, $res['status']);
    },

    'POST /login: fehlgeschlagener Login wird im Audit-Log erfasst' => function (Http $http) {
        $tl = DB::table('audit_log');
        DB::query("DELETE FROM `{$tl}` WHERE event = 'login_failed'");

        $page = $http->get('/login');
        $csrf = extractCsrf($page['body']);

        $http->post('/login', [
            '_csrf'    => $csrf,
            '_form'    => 'admin_login',
            'login'    => TEST_MEMBER_EMAIL,
            'password' => 'wrong-password',
        ]);

        $row = DB::fetch("SELECT * FROM `{$tl}` WHERE event = 'login_failed' ORDER BY id DESC LIMIT 1");
        Assert::true($row !== null, 'Es sollte ein login_failed-Eintrag existieren');
        Assert::same(TEST_MEMBER_EMAIL, $row['email'] ?? null);
    },

    'POST ohne gueltiges CSRF-Token wird als csrf_failed protokolliert' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);

        $tl = DB::table('audit_log');
        DB::query("DELETE FROM `{$tl}` WHERE event = 'csrf_failed'");

        $http->post('/admin/backup', ['_csrf' => 'invalid-token', '_action' => 'create']);

        $row = DB::fetch("SELECT * FROM `{$tl}` WHERE event = 'csrf_failed' ORDER BY id DESC LIMIT 1");
        Assert::true($row !== null, 'Es sollte ein csrf_failed-Eintrag existieren');
        $details = json_decode($row['details'] ?? '{}', true);
        Assert::same('/admin/backup', $details['path'] ?? null);
        Assert::same('POST', $details['method'] ?? null);
        Assert::same('create', $details['_action'] ?? null);
    },
];
