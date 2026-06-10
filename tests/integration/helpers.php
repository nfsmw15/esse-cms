<?php

declare(strict_types=1);

// Gemeinsame Helfer fuer die Integrationstests (LoginTest, VisibilityTest, ...).

function extractCsrf(string $html): string
{
    if (!preg_match('/name="_csrf"\s+value="([0-9a-f]{64})"/', $html, $m)) {
        throw new \RuntimeException('CSRF-Token nicht im HTML gefunden');
    }
    return $m[1];
}

// Loggt den uebergebenen Http-Client per /login ein (gleiche Cookie-Jar danach eingeloggt).
function loginAs(Http $http, string $email, string $password): void
{
    $page = $http->get('/login');
    $csrf = extractCsrf($page['body']);

    $res = $http->post('/login', [
        '_csrf'    => $csrf,
        '_form'    => 'admin_login',
        'login'    => $email,
        'password' => $password,
    ]);

    if ($res['status'] !== 302) {
        throw new \RuntimeException("Login als {$email} fehlgeschlagen (Status {$res['status']})");
    }
}
