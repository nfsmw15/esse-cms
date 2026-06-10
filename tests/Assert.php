<?php

declare(strict_types=1);

// Minimaler Assertion-Helfer fuer den Test-Runner (tests/run.php) — bewusst ohne
// PHPUnit/Composer, im Stil des restigen Projekts (keine externen Abhaengigkeiten).
final class Assert
{
    public static function true(bool $cond, string $msg = 'expected true'): void
    {
        if (!$cond) throw new \RuntimeException($msg);
    }

    public static function false(bool $cond, string $msg = 'expected false'): void
    {
        if ($cond) throw new \RuntimeException($msg);
    }

    public static function same(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($msg ?: sprintf(
                'expected %s, got %s',
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }
}
