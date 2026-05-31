<?php

declare(strict_types=1);

namespace Esse;

class Container
{
    private static array $bindings   = [];
    private static array $singletons = [];
    private static array $instances  = [];

    // Register a factory — every Container::get() call creates a new instance
    public static function bind(string $abstract, callable $factory): void
    {
        self::$bindings[$abstract] = $factory;
    }

    // Register a factory — first call creates the instance, subsequent calls return it cached
    public static function singleton(string $abstract, callable $factory): void
    {
        self::$singletons[$abstract] = $factory;
    }

    // Register an already-created instance directly
    public static function instance(string $abstract, object $instance): void
    {
        self::$instances[$abstract] = $instance;
    }

    // Resolve a binding. Throws if nothing is registered for $abstract.
    public static function get(string $abstract): mixed
    {
        // Pre-built instance
        if (isset(self::$instances[$abstract])) {
            return self::$instances[$abstract];
        }

        // Singleton: build once, cache forever
        if (isset(self::$singletons[$abstract])) {
            self::$instances[$abstract] = (self::$singletons[$abstract])();
            unset(self::$singletons[$abstract]);
            return self::$instances[$abstract];
        }

        // Regular binding: new instance each time
        if (isset(self::$bindings[$abstract])) {
            return (self::$bindings[$abstract])();
        }

        throw new \RuntimeException("Container: nothing bound for '{$abstract}'");
    }

    // Returns true if $abstract is registered in any form
    public static function has(string $abstract): bool
    {
        return isset(self::$instances[$abstract])
            || isset(self::$singletons[$abstract])
            || isset(self::$bindings[$abstract]);
    }

    // Remove a binding (useful in tests)
    public static function forget(string $abstract): void
    {
        unset(self::$instances[$abstract], self::$singletons[$abstract], self::$bindings[$abstract]);
    }
}
