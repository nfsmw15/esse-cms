<?php

declare(strict_types=1);

namespace Esse;

class Hooks
{
    private static array $listeners = [];

    // Register a listener for an event.
    // Lower priority number = runs first (same convention as WordPress, familiar to most devs).
    public static function on(string $event, callable $callback, int $priority = 10): void
    {
        self::$listeners[$event][$priority][] = $callback;
    }

    // Fire an event. All listeners are called; return values are ignored.
    public static function fire(string $event, mixed ...$args): void
    {
        foreach (self::sorted($event) as $callback) {
            $callback(...$args);
        }
    }

    // Pass a value through listeners. Each listener receives the value and must return it.
    public static function filter(string $event, mixed $value, mixed ...$args): mixed
    {
        foreach (self::sorted($event) as $callback) {
            $value = $callback($value, ...$args);
        }
        return $value;
    }

    // Returns true if at least one listener is registered for this event.
    public static function has(string $event): bool
    {
        return !empty(self::$listeners[$event]);
    }

    // Remove all listeners for an event (useful in tests).
    public static function clear(string $event): void
    {
        unset(self::$listeners[$event]);
    }

    private static function sorted(string $event): array
    {
        if (empty(self::$listeners[$event])) return [];
        ksort(self::$listeners[$event]);
        return array_merge(...self::$listeners[$event]);
    }
}
