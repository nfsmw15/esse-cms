<?php

declare(strict_types=1);

namespace Esse;

class Crypto
{
    private const CIPHER = 'AES-256-CBC';
    private const PREFIX = 'ENC:';

    // Encrypt a value. Returns a prefixed base64 string.
    public static function encrypt(string $value): string
    {
        $key = self::key();
        $iv  = random_bytes(16);
        $enc = openssl_encrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        return self::PREFIX . base64_encode($iv . $enc);
    }

    // Decrypt a value. Returns original string, or the value unchanged if not encrypted.
    public static function decrypt(string $value): string
    {
        if (!str_starts_with($value, self::PREFIX)) return $value;

        $key  = self::key();
        $raw  = base64_decode(substr($value, strlen(self::PREFIX)));
        $iv   = substr($raw, 0, 16);
        $enc  = substr($raw, 16);
        $dec  = openssl_decrypt($enc, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        return $dec !== false ? $dec : $value;
    }

    // Returns true if the value is already encrypted
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    private static function key(): string
    {
        if (!defined('ESSE_ENCRYPT_KEY') || strlen(\ESSE_ENCRYPT_KEY) < 32) {
            throw new \RuntimeException('ESSE_ENCRYPT_KEY nicht gesetzt oder zu kurz.');
        }
        return substr(\ESSE_ENCRYPT_KEY, 0, 32);
    }
}
