<?php

declare(strict_types=1);

use Esse\Crypto;

defined('ESSE_ENCRYPT_KEY') || define('ESSE_ENCRYPT_KEY', bin2hex(random_bytes(32)));

return [
    'encrypt/decrypt: Roundtrip im neuen sodium-Format' => function () {
        $plain = 'super-secret-value';
        $enc   = Crypto::encrypt($plain);
        Assert::true(str_starts_with($enc, 'ENC2:'), 'erwartet ENC2:-Praefix, bekam: ' . $enc);
        Assert::same($plain, Crypto::decrypt($enc));
    },

    'decrypt: liest einen alten ENC:-Wert (Legacy AES-256-CBC) weiterhin korrekt' => function () {
        // Mit demselben Schluessel-Schema wie das alte Crypto::key() (substr(KEY, 0, 32)) erzeugt.
        $key   = substr(\ESSE_ENCRYPT_KEY, 0, 32);
        $iv    = random_bytes(16);
        $plain = 'legacy-secret';
        $enc   = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        $value = 'ENC:' . base64_encode($iv . $enc);

        Assert::same($plain, Crypto::decrypt($value));
    },

    'decrypt: manipulierter ENC2:-Wert wird verworfen (Integritaetsschutz)' => function () {
        $enc = Crypto::encrypt('original');
        // Letztes Zeichen der Base64-Payload kippen, um den Ciphertext zu manipulieren.
        $tampered = substr($enc, 0, -1) . (substr($enc, -1) === 'A' ? 'B' : 'A');
        Assert::same($tampered, Crypto::decrypt($tampered), 'manipulierter Wert sollte unveraendert zurueckgegeben werden');
    },

    'isEncrypted: erkennt beide Praefixe und lehnt Klartext ab' => function () {
        Assert::true(Crypto::isEncrypted(Crypto::encrypt('x')));
        Assert::true(Crypto::isEncrypted('ENC:abc'));
        Assert::false(Crypto::isEncrypted('plain text'));
    },
];
