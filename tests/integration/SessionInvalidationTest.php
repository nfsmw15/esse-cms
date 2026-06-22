<?php

declare(strict_types=1);

use Esse\DB;

return [
    'Passwortänderung in einer Session invalidiert andere Sessions desselben Nutzers' => function (Http $httpA) {
        $httpB = new Http(TEST_BASE_URL);

        loginAs($httpA, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        loginAs($httpB, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);

        // Sanity check: beide Sessions sind zu Beginn gueltig.
        Assert::same(200, $httpB->get('/admin')['status'], 'Session B sollte vor der Passwortaenderung gueltig sein');

        // password_changed_at hat (wie die uebrigen Zeitstempel im Projekt) Sekundenaufloesung —
        // sicherstellen, dass die Aenderung unten in einer spaeteren Sekunde als die Logins landet.
        sleep(1);

        $tempPassword = 'Temp-Pass-' . bin2hex(random_bytes(4));
        $csrf = extractCsrf($httpA->get('/profil')['body']);
        $res  = $httpA->post('/profil', [
            '_csrf'            => $csrf,
            '_action'          => 'update_profile',
            'display_name'     => 'Forge Test',
            'email'            => TEST_FORGE_EMAIL,
            'password'         => $tempPassword,
            'password_confirm' => $tempPassword,
            'confirm_password' => TEST_FORGE_PASSWORD,
        ]);

        try {
            Assert::same(200, $res['status']);
            Assert::true(str_contains($res['body'], 'Profil gespeichert'), 'Erfolgsmeldung erwartet, Body: ' . substr($res['body'], 0, 300));

            // Session A (hat das Passwort selbst geaendert) bleibt gueltig.
            Assert::same(200, $httpA->get('/admin')['status'], 'Session A sollte nach eigener Passwortaenderung gueltig bleiben');

            // Session B (andere Session, kennt das neue Passwort nicht) muss jetzt ungueltig sein.
            $resB = $httpB->get('/admin');
            Assert::same(302, $resB['status'], 'Session B sollte nach Passwortaenderung in Session A ungueltig sein');
            $location = $resB['headers']['location'][0] ?? '';
            Assert::true(str_starts_with($location, '/login'), "Redirect zu /login erwartet, war: {$location}");
        } finally {
            // Zurueck in den Ausgangszustand, unabhaengig vom Testergebnis.
            $tu = DB::table('users');
            DB::update(
                $tu,
                ['password' => password_hash(TEST_FORGE_PASSWORD, PASSWORD_BCRYPT), 'password_changed_at' => null],
                ['email' => TEST_FORGE_EMAIL]
            );
        }
    },
];
