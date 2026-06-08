<?php

declare(strict_types=1);

namespace Esse;

use ReportUri\Passkeys\WebAuthn as PasskeyEngine;
use ReportUri\Passkeys\Binary\ByteBuffer;
use ReportUri\Passkeys\WebAuthnException;

// Dünner Wrapper um die vendorte report-uri/passkeys-php-Bibliothek (vendor/webauthn/).
// Deckt zwei unabhängige Ceremonies ab:
//   - Registrierung eines Passkeys (discoverable credential, von pages/profil.php aus)
//   - passwortlose Anmeldung damit (von admin/login.php aus, Nutzer noch nicht identifiziert)
// Der gesamte Umgang mit der Vendor-Lib ist hier gekapselt — der Rest der App kennt nur
// diese Schnittstelle (Arrays/JSON statt ByteBuffer/stdClass).
class WebAuthn
{
    private const SESSION_CHALLENGE = 'esse_webauthn_challenge';

    public static function isAvailable(): bool
    {
        return function_exists('openssl_open');
    }

    // -- Registrierung (Nutzer ist eingeloggt, fügt einen Passkey hinzu) --

    public static function registrationOptions(array $user): array
    {
        $engine     = self::engine();
        $excludeIds = array_map([self::class, 'decodeBase64'], self::credentialIdsForUser((int) $user['id']));

        $args = $engine->getCreateArgs(
            (string) $user['id'],
            (string) $user['email'],
            (string) $user['display_name'],
            60,
            true,  // residentKey: required → discoverable credential (Passkey)
            true,  // userVerification: required (Biometrie/PIN)
            null,  // sowohl Plattform- als auch externe Authenticatoren erlauben
            $excludeIds
        );

        $_SESSION[self::SESSION_CHALLENGE] = $engine->getChallenge()->getBinaryString();

        return self::toArray($args);
    }

    public static function verifyRegistration(array $user, array $clientResponse, string $label): void
    {
        $challenge = self::takeChallenge();
        if ($challenge === null) {
            throw new \RuntimeException('Keine aktive Registrierungs-Anfrage (Challenge abgelaufen).');
        }

        $response = $clientResponse['response'] ?? [];
        $clientDataJSON    = self::decodeBase64Url((string) ($response['clientDataJSON'] ?? ''));
        $attestationObject = self::decodeBase64Url((string) ($response['attestationObject'] ?? ''));

        $data = self::engine()->processCreate($clientDataJSON, $attestationObject, $challenge, true, true);

        $tw = DB::table('webauthn_credentials');
        DB::insert($tw, [
            'user_id'       => (int) $user['id'],
            'credential_id' => self::encodeBase64($data->credentialId),
            'public_key'    => $data->credentialPublicKey,
            'sign_counter'  => $data->signatureCounter ?? 0,
            'label'         => $label !== '' ? $label : 'Passkey',
        ]);
    }

    // -- Passwortlose Anmeldung (Nutzer noch nicht bekannt — Identifikation über credential_id) --

    public static function passwordlessAuthOptions(): array
    {
        $engine = self::engine();
        // Keine allowCredentials-Einschränkung: der Browser zeigt dem Nutzer selbst die
        // verfügbaren Passkeys an (discoverable-credential-Flow), keine Vorab-Identifikation nötig.
        $args = $engine->getGetArgs([], 60, true, true, true, true, true, true);

        $_SESSION[self::SESSION_CHALLENGE] = $engine->getChallenge()->getBinaryString();

        return self::toArray($args);
    }

    public static function verifyPasswordlessAuth(array $clientResponse): ?array
    {
        $challenge = self::takeChallenge();
        if ($challenge === null) return null;

        $rawId = self::decodeBase64Url((string) ($clientResponse['rawId'] ?? ''));
        if ($rawId === '') return null;

        $tw   = DB::table('webauthn_credentials');
        $tu   = DB::table('users');
        $cred = DB::fetch("SELECT * FROM `{$tw}` WHERE credential_id = ?", [self::encodeBase64($rawId)]);
        if (!$cred) return null;

        $user = DB::fetch("SELECT * FROM `{$tu}` WHERE id = ? AND active = 1", [$cred['user_id']]);
        if (!$user) return null;

        $response          = $clientResponse['response'] ?? [];
        $clientDataJSON    = self::decodeBase64Url((string) ($response['clientDataJSON'] ?? ''));
        $authenticatorData = self::decodeBase64Url((string) ($response['authenticatorData'] ?? ''));
        $signature         = self::decodeBase64Url((string) ($response['signature'] ?? ''));

        $engine = self::engine();
        try {
            $engine->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                $cred['public_key'],
                $challenge,
                (int) $cred['sign_counter'],
                true,
                true
            );
        } catch (WebAuthnException) {
            return null;
        }

        $newCounter = $engine->getSignatureCounter() ?? (int) $cred['sign_counter'];
        DB::update($tw, [
            'sign_counter' => $newCounter,
            'last_used_at' => date('Y-m-d H:i:s'),
        ], ['id' => $cred['id']]);

        return $user;
    }

    // -- Verwaltung (für den Sicherheits-Bereich in pages/profil.php) --

    public static function credentialsForUser(int $userId): array
    {
        $tw = DB::table('webauthn_credentials');
        return DB::fetchAll(
            "SELECT id, label, created_at, last_used_at FROM `{$tw}` WHERE user_id = ? ORDER BY created_at ASC",
            [$userId]
        );
    }

    public static function removeCredential(int $userId, int $credentialId): bool
    {
        $tw = DB::table('webauthn_credentials');
        return DB::delete($tw, ['id' => $credentialId, 'user_id' => $userId]) > 0;
    }

    public static function renameCredential(int $userId, int $credentialId, string $label): bool
    {
        $tw = DB::table('webauthn_credentials');
        return DB::update($tw, ['label' => $label], ['id' => $credentialId, 'user_id' => $userId]) > 0;
    }

    // -- DB-Migration --

    public static function migrateDb(): void
    {
        $tw = DB::table('webauthn_credentials');

        DB::query("CREATE TABLE IF NOT EXISTS `{$tw}` (
            `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id`       INT UNSIGNED NOT NULL,
            `credential_id` VARCHAR(1024) NOT NULL,
            `public_key`    TEXT NOT NULL,
            `sign_counter`  INT UNSIGNED NOT NULL DEFAULT 0,
            `label`         VARCHAR(100) NOT NULL DEFAULT '',
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_used_at`  DATETIME NULL,
            KEY `idx_user` (`user_id`),
            UNIQUE KEY `uq_credential_id` (`credential_id`(255))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // -- Internals --

    private static function engine(): PasskeyEngine
    {
        // useBase64UrlEncoding=true: ByteBuffer-Werte werden bei json_encode() als
        // Base64URL-Strings serialisiert — das Format, das webauthn.js direkt in
        // ArrayBuffer zurückwandelt.
        return new PasskeyEngine(self::rpName(), self::rpId(), true);
    }

    public static function rpId(): string
    {
        $ts      = DB::table('settings');
        $siteUrl = (string) (DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'site_url'") ?? '');
        $host    = $siteUrl !== '' ? parse_url($siteUrl, PHP_URL_HOST) : null;
        if ($host) return $host;

        return (string) ($_SERVER['HTTP_HOST'] ?? parse_url($_SERVER['SERVER_NAME'] ?? 'localhost', PHP_URL_HOST) ?? 'localhost');
    }

    public static function rpName(): string
    {
        $ts = DB::table('settings');
        return (string) (DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'site_name'") ?: 'ESSE CMS');
    }

    private static function takeChallenge(): ?string
    {
        $challenge = $_SESSION[self::SESSION_CHALLENGE] ?? null;
        unset($_SESSION[self::SESSION_CHALLENGE]);
        return is_string($challenge) ? $challenge : null;
    }

    private static function credentialIdsForUser(int $userId): array
    {
        $tw  = DB::table('webauthn_credentials');
        $ids = DB::fetchAll("SELECT credential_id FROM `{$tw}` WHERE user_id = ?", [$userId]);
        return array_column($ids, 'credential_id');
    }

    // stdClass mit ByteBuffer-Werten -> assoziatives Array mit Base64URL-Strings
    // (jsonSerialize() der ByteBuffer übernimmt die Kodierung dank useBase64UrlEncoding=true)
    private static function toArray(\stdClass $args): array
    {
        return json_decode(json_encode($args), true);
    }

    private static function encodeBase64(string $binary): string
    {
        return base64_encode($binary);
    }

    private static function decodeBase64(string $base64): string
    {
        return (string) base64_decode($base64);
    }

    private static function decodeBase64Url(string $value): string
    {
        if ($value === '') return '';
        return ByteBuffer::fromBase64Url($value)->getBinaryString();
    }
}
