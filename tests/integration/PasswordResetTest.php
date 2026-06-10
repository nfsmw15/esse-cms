<?php

declare(strict_types=1);

use Esse\DB;

// Legt einen Passwort-Reset-Token fuer die angegebene E-Mail an (optional mit
// abweichendem created_at, um Ablauf zu simulieren) und gibt den Token zurueck.
function makeResetToken(string $email, ?string $createdAt = null): string
{
    $tr    = DB::table('password_resets');
    $token = bin2hex(random_bytes(32));
    DB::delete($tr, ['email' => $email]);
    DB::insert($tr, ['token' => $token, 'email' => $email, 'created_at' => $createdAt ?? date('Y-m-d H:i:s')]);
    return $token;
}

return [
    'POST /admin/forgot-password: ohne CSRF-Token wird abgelehnt' => function (Http $http) {
        $res = $http->post('/admin/forgot-password', ['email' => TEST_MEMBER_EMAIL]);
        Assert::same(403, $res['status']);
    },

    'GET /admin/reset-password: ungueltiger Token zeigt Fehlermeldung' => function (Http $http) {
        $res = $http->get('/admin/reset-password?token=ungueltig');

        Assert::same(200, $res['status']);
        Assert::true(str_contains($res['body'], 'ungültig oder abgelaufen'), 'Fehlermeldung fuer ungueltigen Token erwartet');
        Assert::false(str_contains($res['body'], 'name="password"'), 'Kein Passwort-Formular bei ungueltigem Token erwartet');
    },

    'GET /admin/reset-password: abgelaufener Token wird abgelehnt und geloescht' => function (Http $http) {
        $token = makeResetToken(TEST_MEMBER_EMAIL, date('Y-m-d H:i:s', time() - 3700));

        $res = $http->get('/admin/reset-password?token=' . $token);

        Assert::same(200, $res['status']);
        Assert::true(str_contains($res['body'], 'ungültig oder abgelaufen'), 'Fehlermeldung fuer abgelaufenen Token erwartet');

        $tr  = DB::table('password_resets');
        $row = DB::fetch("SELECT * FROM `{$tr}` WHERE token = ?", [$token]);
        Assert::true($row === null, 'Abgelaufener Token sollte aus der DB geloescht werden');
    },

    'GET /admin/reset-password: gueltiger Token zeigt Formular' => function (Http $http) {
        $token = makeResetToken(TEST_MEMBER_EMAIL);

        $res = $http->get('/admin/reset-password?token=' . $token);

        Assert::same(200, $res['status']);
        Assert::true(str_contains($res['body'], 'name="password"'), 'Passwort-Formular erwartet');
        Assert::false(str_contains($res['body'], 'ungültig oder abgelaufen'), 'Keine Fehlermeldung bei gueltigem Token erwartet');
    },

    'POST /admin/reset-password: zu kurzes Passwort wird abgelehnt' => function (Http $http) {
        $token = makeResetToken(TEST_MEMBER_EMAIL);
        $page  = $http->get('/admin/reset-password?token=' . $token);
        $csrf  = extractCsrf($page['body']);

        $res = $http->post('/admin/reset-password', [
            '_csrf'            => $csrf,
            'token'            => $token,
            'password'         => 'kurz123',
            'password_confirm' => 'kurz123',
        ]);

        Assert::same(200, $res['status']);
        Assert::true(str_contains($res['body'], 'mindestens 10 Zeichen'), 'Fehlermeldung "mindestens 10 Zeichen" erwartet');

        $tr  = DB::table('password_resets');
        $row = DB::fetch("SELECT * FROM `{$tr}` WHERE token = ?", [$token]);
        Assert::true($row !== null, 'Token bleibt nach fehlgeschlagenem Versuch gueltig');
    },

    'POST /admin/reset-password: unterschiedliche Passwoerter werden abgelehnt' => function (Http $http) {
        $token = makeResetToken(TEST_MEMBER_EMAIL);
        $page  = $http->get('/admin/reset-password?token=' . $token);
        $csrf  = extractCsrf($page['body']);

        $res = $http->post('/admin/reset-password', [
            '_csrf'            => $csrf,
            'token'            => $token,
            'password'         => 'Neues-Passwort1',
            'password_confirm' => 'Anderes-Passwort1',
        ]);

        Assert::same(200, $res['status']);
        Assert::true(str_contains($res['body'], 'stimmen nicht überein'), 'Fehlermeldung "stimmen nicht ueberein" erwartet');
    },

    'POST /admin/reset-password: erfolgreich, danach einmalig nutzbar und Login mit neuem Passwort' => function (Http $http) {
        $token = makeResetToken(TEST_MEMBER_EMAIL);
        $page  = $http->get('/admin/reset-password?token=' . $token);
        $csrf  = extractCsrf($page['body']);

        $newPassword = 'Brandneues-Passwort1';
        $res = $http->post('/admin/reset-password', [
            '_csrf'            => $csrf,
            'token'            => $token,
            'password'         => $newPassword,
            'password_confirm' => $newPassword,
        ]);

        Assert::same(200, $res['status']);
        Assert::true(str_contains($res['body'], 'erfolgreich geändert'), 'Erfolgsmeldung erwartet');

        // Token darf nicht erneut nutzbar sein.
        $res2 = $http->get('/admin/reset-password?token=' . $token);
        Assert::true(str_contains($res2['body'], 'ungültig oder abgelaufen'), 'Token sollte nach Verwendung ungueltig sein');

        // Login mit neuem Passwort funktioniert.
        $loginPage = $http->get('/login');
        $loginCsrf = extractCsrf($loginPage['body']);
        $loginRes  = $http->post('/login', [
            '_csrf'    => $loginCsrf,
            '_form'    => 'admin_login',
            'login'    => TEST_MEMBER_EMAIL,
            'password' => $newPassword,
        ]);
        Assert::same(302, $loginRes['status']);

        // Aufraeumen: Passwort wieder auf den Test-Standard zuruecksetzen.
        $tu = DB::table('users');
        DB::update($tu, ['password' => password_hash(TEST_MEMBER_PASSWORD, PASSWORD_BCRYPT)], ['email' => TEST_MEMBER_EMAIL]);
    },
];
