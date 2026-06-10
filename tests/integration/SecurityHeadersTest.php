<?php

declare(strict_types=1);

return [
    'GET /: Security-Header werden gesetzt' => function (Http $http) {
        $res = $http->get('/');

        Assert::same(['nosniff'], $res['headers']['x-content-type-options'] ?? null);
        Assert::same(['SAMEORIGIN'], $res['headers']['x-frame-options'] ?? null);
        Assert::same(['strict-origin-when-cross-origin'], $res['headers']['referrer-policy'] ?? null);
        Assert::true(isset($res['headers']['permissions-policy']), 'Permissions-Policy-Header erwartet');

        $csp = $res['headers']['content-security-policy'][0] ?? '';
        Assert::true(str_contains($csp, "default-src 'self'"), "CSP enthaelt default-src 'self' erwartet");
        Assert::true(str_contains($csp, "frame-ancestors 'self'"), "CSP enthaelt frame-ancestors 'self' erwartet");
        Assert::true(str_contains($csp, "script-src 'self'"), "CSP enthaelt script-src 'self' erwartet");
    },

    'GET /login: Security-Header werden auch auf Login-Seite gesetzt' => function (Http $http) {
        $res = $http->get('/login');

        Assert::same(['nosniff'], $res['headers']['x-content-type-options'] ?? null);
        Assert::true(isset($res['headers']['content-security-policy']), 'CSP-Header erwartet');
    },
];
