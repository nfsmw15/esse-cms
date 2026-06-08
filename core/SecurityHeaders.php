<?php

declare(strict_types=1);

namespace Esse;

class SecurityHeaders
{
    public static function send(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) return;

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), bluetooth=()');

        $csp = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "connect-src 'self'",
        ];

        if (self::isHttps()) {
            $csp[] = 'upgrade-insecure-requests';
        }

        header('Content-Security-Policy: ' . implode('; ', $csp));
    }

    public static function isHttps(): bool
    {
        if (defined('ESSE_URL') && str_starts_with((string) \ESSE_URL, 'https://')) {
            return true;
        }

        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
