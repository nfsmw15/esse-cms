<?php

declare(strict_types=1);

use Esse\Hooks;

return [
    'fire: ruft alle registrierten Listener mit den Argumenten auf' => function () {
        Hooks::clear('test.fire');
        $calls = [];
        Hooks::on('test.fire', function ($x) use (&$calls) { $calls[] = "a:{$x}"; });
        Hooks::on('test.fire', function ($x) use (&$calls) { $calls[] = "b:{$x}"; });
        Hooks::fire('test.fire', 'X');
        Assert::same(['a:X', 'b:X'], $calls);
    },

    'fire: niedrigere Prioritaet laeuft zuerst' => function () {
        Hooks::clear('test.priority');
        $order = [];
        Hooks::on('test.priority', function () use (&$order) { $order[] = 'spaet'; }, 20);
        Hooks::on('test.priority', function () use (&$order) { $order[] = 'frueh'; }, 5);
        Hooks::fire('test.priority');
        Assert::same(['frueh', 'spaet'], $order);
    },

    'filter: reicht den Wert nacheinander durch alle Listener' => function () {
        Hooks::clear('test.filter');
        Hooks::on('test.filter', fn($v) => $v . '-a');
        Hooks::on('test.filter', fn($v) => $v . '-b');
        Assert::same('start-a-b', Hooks::filter('test.filter', 'start'));
    },

    'has: erkennt registrierte und nicht registrierte Events' => function () {
        Hooks::clear('test.has');
        Assert::false(Hooks::has('test.has'));
        Hooks::on('test.has', fn() => null);
        Assert::true(Hooks::has('test.has'));
    },

    'clear: entfernt alle Listener eines Events' => function () {
        Hooks::on('test.clear', fn() => null);
        Hooks::clear('test.clear');
        Assert::false(Hooks::has('test.clear'));
    },

    'fire: Event ohne Listener bleibt folgenlos' => function () {
        Hooks::clear('test.empty');
        Hooks::fire('test.empty', 'irrelevant'); // darf nicht werfen
        Assert::false(Hooks::has('test.empty'));
    },
];
