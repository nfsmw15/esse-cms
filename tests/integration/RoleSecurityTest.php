<?php

declare(strict_types=1);

use Esse\DB;

return [
    'POST /admin/users (Selbst-Herabstufung von Forge): blockiert ohne zweiten aktiven Forge' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);

        $tu      = DB::table('users');
        $forgeId = (int) DB::value("SELECT id FROM `{$tu}` WHERE email = ?", [TEST_FORGE_EMAIL]);

        $csrf = extractCsrf($http->get("/admin/users/edit/{$forgeId}")['body']);
        $res  = $http->post("/admin/users/edit/{$forgeId}", [
            '_csrf'        => $csrf,
            '_action'      => 'save',
            'display_name' => 'Forge Test',
            'email'        => TEST_FORGE_EMAIL,
            'role'         => 'admin',
        ]);

        Assert::same(200, $res['status']);
        Assert::true(str_contains($res['body'], 'einzige aktive Forge-Account'), 'Blockierende Fehlermeldung erwartet');
        Assert::same('forge', DB::value("SELECT role FROM `{$tu}` WHERE id = ?", [$forgeId]), 'Rolle sollte unveraendert bleiben');
    },

    'POST /admin/users (Selbst-Herabstufung von Forge): erfordert Bestaetigung, wenn ein zweiter Forge existiert' => function (Http $http) {
        $tu = DB::table('users');
        $secondForgeEmail = 'forge2-' . bin2hex(random_bytes(4)) . '@example.test';
        $secondForgeId = DB::insert($tu, [
            'display_name' => 'Second Forge',
            'email'        => $secondForgeEmail,
            'password'     => password_hash('Second-Forge-Pass1', PASSWORD_BCRYPT),
            'role'         => 'forge',
            'active'       => 1,
        ]);

        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $forgeId = (int) DB::value("SELECT id FROM `{$tu}` WHERE email = ?", [TEST_FORGE_EMAIL]);

        try {
            $csrf = extractCsrf($http->get("/admin/users/edit/{$forgeId}")['body']);

            // Ohne Bestaetigung: Warnhinweis statt sofortiger Aenderung
            $res1 = $http->post("/admin/users/edit/{$forgeId}", [
                '_csrf' => $csrf, '_action' => 'save',
                'display_name' => 'Forge Test', 'email' => TEST_FORGE_EMAIL, 'role' => 'admin',
            ]);
            Assert::same(200, $res1['status']);
            Assert::true(str_contains($res1['body'], 'eigene Forge-Rechte abgeben'), 'Bestaetigungs-Warnung erwartet');
            Assert::same('forge', DB::value("SELECT role FROM `{$tu}` WHERE id = ?", [$forgeId]), 'Rolle sollte ohne Bestaetigung unveraendert bleiben');

            // Mit Bestaetigung: Aenderung greift
            $csrf2 = extractCsrf($res1['body']);
            $res2  = $http->post("/admin/users/edit/{$forgeId}", [
                '_csrf' => $csrf2, '_action' => 'save',
                'display_name' => 'Forge Test', 'email' => TEST_FORGE_EMAIL, 'role' => 'admin',
                'forge_demote_confirmed' => '1',
            ]);
            Assert::same(302, $res2['status']);
            Assert::same('admin', DB::value("SELECT role FROM `{$tu}` WHERE id = ?", [$forgeId]), 'Rolle sollte nach Bestaetigung admin sein');
        } finally {
            DB::update($tu, ['role' => 'forge'], ['id' => $forgeId]);
            DB::delete($tu, ['id' => $secondForgeId]);
        }
    },
];
