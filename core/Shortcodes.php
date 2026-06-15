<?php

declare(strict_types=1);

namespace Esse;

class Shortcodes
{
    /** @var array<string, array{handler: callable, meta: array}> */
    private static array $registry = [];

    // Register a shortcode tag with its handler and metadata for the picker dialog.
    public static function register(string $tag, callable $handler, array $meta = []): void
    {
        self::$registry[$tag] = ['handler' => $handler, 'meta' => $meta];
    }

    // Returns tag => meta for all registered shortcodes (for the admin picker).
    public static function getRegistered(): array
    {
        $result = [];
        foreach (self::$registry as $tag => $entry) {
            $result[$tag] = $entry['meta'];
        }
        return $result;
    }

    // Replaces [tag attr="value"] occurrences in $content with the handler's output.
    public static function render(string $content, array $page = []): string
    {
        return preg_replace_callback(
            '/\[(\w+)((?:\s+[\w-]+="[^"]*")*)\s*\]/',
            function (array $m): string {
                $tag = $m[1];
                if (!isset(self::$registry[$tag])) {
                    return $m[0];
                }

                $attrs = [];
                if (preg_match_all('/([\w-]+)="([^"]*)"/', $m[2], $am, PREG_SET_ORDER)) {
                    foreach ($am as $a) {
                        $attrs[$a[1]] = $a[2];
                    }
                }

                try {
                    return (string) (self::$registry[$tag]['handler'])($attrs);
                } catch (\Throwable $e) {
                    error_log('Shortcode [' . $tag . '] failed: ' . $e->getMessage());
                    return '';
                }
            },
            $content
        );
    }
}
