<?php

declare(strict_types=1);

use Esse\AuditLog;

return [
    'EVENTS: jeder Schluessel hat ein nicht-leeres deutsches Label' => function () {
        Assert::true(count(AuditLog::EVENTS) > 0, 'EVENTS darf nicht leer sein');
        foreach (AuditLog::EVENTS as $slug => $label) {
            Assert::true(is_string($slug) && $slug !== '', 'Event-Schluessel darf nicht leer sein');
            Assert::true(is_string($label) && $label !== '', "Label fuer '{$slug}' darf nicht leer sein");
        }
    },

    'clientIp: liefert REMOTE_ADDR' => function () {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
        Assert::same('203.0.113.42', AuditLog::clientIp());
    },

    'clientIp: liefert null ohne REMOTE_ADDR' => function () {
        unset($_SERVER['REMOTE_ADDR']);
        Assert::same(null, AuditLog::clientIp());
    },

    'record: wirft nie, auch ohne DB-Verbindung' => function () {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
        AuditLog::record('login_failed', null, 'test@example.com', ['foo' => 'bar']);
        Assert::true(true, 'record() darf keine Exception werfen');
    },
];
