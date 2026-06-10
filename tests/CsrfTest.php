<?php

declare(strict_types=1);

use Esse\Auth;

return [
    'csrfToken: erzeugt 64-stelliges Hex-Token und ist stabil pro Session' => function () {
        $_SESSION = [];
        $token = Auth::csrfToken();
        Assert::same(64, strlen($token));
        Assert::true((bool) preg_match('/^[0-9a-f]{64}$/', $token));
        Assert::same($token, Auth::csrfToken(), 'wiederholter Aufruf sollte dasselbe Token liefern');
    },

    'verifyCsrf: akzeptiert gueltiges Token aus $_POST[_csrf]' => function () {
        $_SESSION = [];
        $_POST    = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);

        $token          = Auth::csrfToken();
        $_POST['_csrf'] = $token;

        Assert::true(Auth::verifyCsrf());
    },

    'verifyCsrf: akzeptiert gueltiges Token aus X-CSRF-Token-Header' => function () {
        $_SESSION = [];
        $_POST    = [];

        $token = Auth::csrfToken();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        Assert::true(Auth::verifyCsrf());
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    },

    'verifyCsrf: lehnt falsches Token ab' => function () {
        $_SESSION = [];
        $_POST    = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);

        Auth::csrfToken();
        $_POST['_csrf'] = 'falsches-token';

        Assert::false(Auth::verifyCsrf());
    },

    'verifyCsrf: lehnt fehlendes Token ab' => function () {
        $_SESSION = [];
        $_POST    = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);

        Auth::csrfToken();

        Assert::false(Auth::verifyCsrf());
    },
];
