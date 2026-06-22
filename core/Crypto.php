<?php

declare(strict_types=1);

namespace Esse;

class Crypto
{
    // Legacy-Format ohne Integritätsschutz — wird nur noch gelesen, nie mehr geschrieben.
    private const CIPHER_LEGACY = 'AES-256-CBC';
    private const PREFIX_LEGACY = 'ENC:';

    // Aktuelles Format: sodium_crypto_secretbox (XSalsa20-Poly1305, AEAD).
    private const PREFIX_SODIUM = 'ENC2:';

    // Encrypt a value. Returns a prefixed base64 string.
    public static function encrypt(string $value): string
    {
        $key   = self::sodiumKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $enc   = sodium_crypto_secretbox($value, $nonce, $key);
        return self::PREFIX_SODIUM . base64_encode($nonce . $enc);
    }

    // Decrypt a value. Returns original string, or the value unchanged if not encrypted/invalid.
    public static function decrypt(string $value): string
    {
        if (str_starts_with($value, self::PREFIX_SODIUM)) return self::decryptSodium($value);
        if (str_starts_with($value, self::PREFIX_LEGACY)) return self::decryptLegacy($value);
        return $value;
    }

    // Returns true if the value is already encrypted (either format)
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX_SODIUM) || str_starts_with($value, self::PREFIX_LEGACY);
    }

    private static function decryptSodium(string $value): string
    {
        $raw = base64_decode(substr($value, strlen(self::PREFIX_SODIUM)));
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return $value;

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $enc   = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $dec   = sodium_crypto_secretbox_open($enc, $nonce, self::sodiumKey());
        return $dec !== false ? $dec : $value;
    }

    private static function decryptLegacy(string $value): string
    {
        $key = self::legacyKey();
        $raw = base64_decode(substr($value, strlen(self::PREFIX_LEGACY)));
        $iv  = substr($raw, 0, 16);
        $enc = substr($raw, 16);
        $dec = openssl_decrypt($enc, self::CIPHER_LEGACY, $key, OPENSSL_RAW_DATA, $iv);
        return $dec !== false ? $dec : $value;
    }

    // Bringt ESSE_ENCRYPT_KEY deterministisch auf die volle Schlüssellänge für sodium
    // (nutzt dabei die volle Entropie des Hex-Strings, anders als legacyKey()).
    private static function sodiumKey(): string
    {
        return sodium_crypto_generichash(self::keyMaterial(), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    // Unverändert gegenüber dem ursprünglichen Format — nur für decryptLegacy() relevant,
    // da bestehende ENC:-Werte exakt mit diesem (verkürzten) Schlüssel verschlüsselt wurden.
    private static function legacyKey(): string
    {
        return substr(self::keyMaterial(), 0, 32);
    }

    private static function keyMaterial(): string
    {
        if (!defined('ESSE_ENCRYPT_KEY') || strlen(\ESSE_ENCRYPT_KEY) < 32) {
            throw new \RuntimeException('ESSE_ENCRYPT_KEY nicht gesetzt oder zu kurz.');
        }
        return \ESSE_ENCRYPT_KEY;
    }
}
