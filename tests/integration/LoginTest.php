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

        // Die Sperre ist IP-basiert (nicht session-basiert) und wuerde sonst alle
        // nachfolgenden Tests von derselben Test-IP (127.0.0.1) betreffen.
        \Esse\RateLimit::clear('login:127.0.0.1');
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

    // Regression: ?redirect=... landet ungefiltert im JSON-Block #passkey-login-config.
    // JSON_UNESCAPED_SLASHES bedeutet, dass "/" nicht zu "\/" wird - ohne JSON_HEX_TAG
    // konnte ein redirect-Wert wie "/</script><script>alert(1)</script>" das script-Tag
    // schliessen und beliebiges HTML/Script danach einschleusen (Reflected XSS).
    'GET /login?redirect=...</script>...: JSON-Block bricht nicht aus dem script-Tag aus' => function (Http $http) {
        $payload = '/</script><script>alert(4242)</script>';
        $res = $http->get('/login?redirect=' . rawurlencode($payload));

        $escaped = '\u003Cscript\u003Ealert(4242)';

        Assert::same(200, $res['status']);
        Assert::true(!str_contains($res['body'], '</script><script>alert(4242)'), 'Roher Payload darf das script-Tag nicht schliessen');
        Assert::true(str_contains($res['body'], $escaped), 'redirect-Wert sollte \\u-escaped im JSON-Block auftauchen, nicht stillschweigend verschwinden');
    },
];
