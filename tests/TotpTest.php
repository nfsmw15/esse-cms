<?php

declare(strict_types=1);

use Esse\Totp;

// Ruft die privaten Methoden ueber Reflection auf, um den fuer "verifyCode" jetzt
// gueltigen Code unabhaengig nachzurechnen (RFC 6238, HMAC-SHA1, 30s-Zeitschritt).
function totpCodeAt(string $secretBase32, int $counter): string
{
    $ref     = new ReflectionClass(Totp::class);
    $decode  = $ref->getMethod('base32Decode');
    $codeAt  = $ref->getMethod('codeAt');
    $secret  = $decode->invoke(null, $secretBase32);
    return $codeAt->invoke(null, $secret, $counter);
}

return [
    'generateSecret: liefert 32-stelliges Base32 (160 Bit)' => function () {
        $secret = Totp::generateSecret();
        Assert::same(32, strlen($secret));
        Assert::true((bool) preg_match('/^[A-Z2-7]+$/', $secret), 'enthaelt ungueltige Base32-Zeichen');
    },

    'verifyCode: lehnt Codes mit falschem Format ab' => function () {
        $secret = Totp::generateSecret();
        Assert::false(Totp::verifyCode($secret, 'abcdef'));
        Assert::false(Totp::verifyCode($secret, '12345'));
        Assert::false(Totp::verifyCode($secret, '1234567'));
    },

    'verifyCode: akzeptiert den aktuell gueltigen Code' => function () {
        $secret  = Totp::generateSecret();
        $counter = (int) floor(time() / 30);
        $code    = totpCodeAt($secret, $counter);
        Assert::true(Totp::verifyCode($secret, $code));
    },

    'verifyCode: lehnt einen falschen Code ab' => function () {
        $secret  = Totp::generateSecret();
        $counter = (int) floor(time() / 30);
        $correct = (int) totpCodeAt($secret, $counter);
        $wrong   = str_pad((string) (($correct + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);
        Assert::false(Totp::verifyCode($secret, $wrong));
    },
];
