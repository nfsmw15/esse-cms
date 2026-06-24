<?php

declare(strict_types=1);

use Esse\Plugin;
use Esse\Router;

// Minimale konkrete Plugin-Implementierung nur für diesen Test — $this->route() ist protected,
// braucht also eine Subklasse, um aufgerufen zu werden.
class TestRoutePlugin extends Plugin
{
    public function boot(): void {}

    public function callRoute(string $method, string $pattern, array $options = []): void
    {
        $this->route($method, $pattern, fn() => null, $options);
    }
}

return [
    'Plugin::route(): warnt, wenn "auth" fehlt' => function () {
        $plugin = new TestRoutePlugin();
        $warnings = [];
        set_error_handler(function (int $_errno, string $errstr) use (&$warnings) {
            $warnings[] = $errstr;
            return true;
        }, E_USER_WARNING);

        try {
            $plugin->callRoute('get', '/test-plugin-no-auth-' . uniqid());
        } finally {
            restore_error_handler();
        }

        Assert::true(count($warnings) === 1, 'Es sollte genau eine E_USER_WARNING ausgelöst werden');
        Assert::true(str_contains($warnings[0], "ohne explizites 'auth'"), "Warnung sollte den fehlenden auth-Schlüssel erklären, war: {$warnings[0]}");
    },

    'Plugin::route(): warnt nicht, wenn "auth" explizit gesetzt ist (auch bei "public")' => function () {
        $plugin = new TestRoutePlugin();
        $warnings = [];
        set_error_handler(function (int $_errno, string $errstr) use (&$warnings) {
            $warnings[] = $errstr;
            return true;
        }, E_USER_WARNING);

        try {
            $plugin->callRoute('get', '/test-plugin-explicit-auth-' . uniqid(), ['auth' => 'public']);
        } finally {
            restore_error_handler();
        }

        Assert::true(count($warnings) === 0, 'Bei explizit gesetztem auth sollte keine Warnung ausgelöst werden');
    },

    'Plugin::route(): registriert die Route trotz fehlendem "auth" weiterhin (kein Hartfehler)' => function () {
        $plugin = new TestRoutePlugin();
        $pattern = '/test-plugin-still-registers-' . uniqid();
        @$plugin->callRoute('get', $pattern); // @ unterdrueckt die Warnung fuer diesen Test

        $ref    = new ReflectionClass(Router::class);
        $prop   = $ref->getProperty('routes');
        $routes = $prop->getValue();

        $found = false;
        foreach ($routes as $route) {
            if ($route['method'] === 'GET' && $route['pattern'] === $pattern) {
                $found = true;
                Assert::same('public', $route['auth'], 'Ohne explizites auth sollte weiterhin auf "public" zurueckgefallen werden');
                break;
            }
        }
        Assert::true($found, 'Route sollte trotz fehlender auth-Warnung registriert worden sein');
    },
];
