<?php

declare(strict_types=1);

namespace Esse;

// TOTP nach RFC 6238 (HMAC-SHA1, 30s-Zeitschritt, 6-stellige Codes) — komplett in
// reinem PHP, keine externe Bibliothek nötig (hash_hmac ist Bestandteil von PHP).
class Totp
{
    private const PERIOD     = 30;
    private const DIGITS     = 6;
    private const SECRET_LEN = 20; // 160 Bit, wie von Authenticator-Apps erwartet

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_LEN));
    }

    // Prüft einen 6-stelligen Code gegen das Secret, mit Toleranz von ±$window Zeitschritten
    // (gleicht leichte Uhr-Abweichungen zwischen Server und Authenticator-App aus).
    public static function verifyCode(string $secretBase32, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) return false;

        $secret = self::base32Decode($secretBase32);
        if ($secret === '') return false;

        $counter = (int) floor(time() / self::PERIOD);

        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::codeAt($secret, $counter + $i), $code)) return true;
        }
        return false;
    }

    public static function provisioningUri(string $secretBase32, string $accountLabel, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountLabel);
        $query = http_build_query([
            'secret' => $secretBase32,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return "otpauth://totp/{$label}?{$query}";
    }

    private static function codeAt(string $secret, int $counter): string
    {
        $bin  = pack('J', $counter); // 8-Byte Big-Endian Zähler
        $hash = hash_hmac('sha1', $bin, $secret, true);

        $offset = ord($hash[19]) & 0x0F;
        $value  = ((ord($hash[$offset]) & 0x7F) << 24)
                | (ord($hash[$offset + 1]) << 16)
                | (ord($hash[$offset + 2]) << 8)
                | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    // -- Base32 (RFC 4648) — kein PHP-Built-in --

    private static function base32Encode(string $data): string
    {
        if ($data === '') return '';

        $bits   = '';
        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        // Auf ein Vielfaches von 5 Bit auffüllen
        $bits = str_pad($bits, (int) (ceil(strlen($bits) / 5) * 5), '0', STR_PAD_RIGHT);

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32_ALPHABET[bindec($chunk)];
        }
        return $out;
    }

    private static function base32Decode(string $data): string
    {
        $data = strtoupper(rtrim(trim($data), '='));
        if ($data === '') return '';

        $bits = '';
        foreach (str_split($data) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) return ''; // ungültiges Zeichen — kein valides Secret
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) break; // Auffüll-Bits am Ende ignorieren
            $bytes .= chr(bindec($chunk));
        }
        return $bytes;
    }
}
