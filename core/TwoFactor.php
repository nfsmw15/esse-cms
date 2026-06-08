<?php

declare(strict_types=1);

namespace Esse;

// Fassade für klassisches Zwei-Faktor-Login: TOTP (Authenticator-App) + Backup-Codes.
// Bewusst getrennt von WebAuthn/Passkey — ein Passkey ist eine eigenständige,
// passwortlose Anmeldemethode und kein "zweiter Faktor" zum Passwort.
class TwoFactor
{
    private const BACKUP_CODE_COUNT  = 10;
    private const BACKUP_CODE_LENGTH = 10; // Zeichen, ohne Trennstrich

    public static function isEnabled(array $user): bool
    {
        return !empty($user['totp_enabled']);
    }

    // 10 zufällige Klartext-Codes (z.B. "a1b2c-3d4e5") für die Einmalanzeige beim Setup.
    public static function generateBackupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODE_COUNT; $i++) {
            $codes[] = self::randomBackupCode();
        }
        return $codes;
    }

    // Bcrypt-Hashes der Klartext-Codes — so werden sie in users.totp_backup_codes
    // gespeichert (JSON-Array), nie im Klartext, analog zum Passwort-Hashing.
    public static function hashBackupCodes(array $plainCodes): string
    {
        return json_encode(array_map(
            fn(string $code) => password_hash(self::normalizeBackupCode($code), PASSWORD_BCRYPT),
            $plainCodes
        ));
    }

    // Prüft einen Backup-Code und entfernt ihn bei Erfolg aus dem gespeicherten Array
    // (Einmal-Codes — ein verbrauchter Code ist danach ungültig).
    public static function verifyBackupCode(array $user, string $code): bool
    {
        $code   = self::normalizeBackupCode($code);
        $hashes = json_decode((string) ($user['totp_backup_codes'] ?? ''), true);
        if (!is_array($hashes) || $code === '') return false;

        foreach ($hashes as $i => $hash) {
            if (is_string($hash) && password_verify($code, $hash)) {
                unset($hashes[$i]);
                $tu = DB::table('users');
                DB::update($tu, ['totp_backup_codes' => json_encode(array_values($hashes))], ['id' => $user['id']]);
                return true;
            }
        }
        return false;
    }

    public static function remainingBackupCodes(array $user): int
    {
        $hashes = json_decode((string) ($user['totp_backup_codes'] ?? ''), true);
        return is_array($hashes) ? count($hashes) : 0;
    }

    // -- DB-Migration (analog PageVisibility::migrateDb, aufgerufen aus Auth::syncDefaultPermissions) --

    public static function migrateDb(): void
    {
        $tu = DB::table('users');

        $cols = array_column(DB::fetchAll("SHOW COLUMNS FROM `{$tu}`"), 'Field');

        if (!in_array('totp_secret', $cols, true)) {
            DB::query("ALTER TABLE `{$tu}` ADD COLUMN `totp_secret` VARCHAR(255) NULL");
        }
        if (!in_array('totp_enabled', $cols, true)) {
            DB::query("ALTER TABLE `{$tu}` ADD COLUMN `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('totp_backup_codes', $cols, true)) {
            DB::query("ALTER TABLE `{$tu}` ADD COLUMN `totp_backup_codes` TEXT NULL");
        }

        WebAuthn::migrateDb();
    }

    // -- Internals --

    private static function randomBackupCode(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789'; // ohne i/l/o/0/1 — Verwechslungsgefahr
        $raw = '';
        for ($i = 0; $i < self::BACKUP_CODE_LENGTH; $i++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        // Lesbarkeit: in der Mitte mit Bindestrich gruppieren ("ab3de-fg7hk")
        return substr($raw, 0, 5) . '-' . substr($raw, 5);
    }

    private static function normalizeBackupCode(string $code): string
    {
        return strtolower(str_replace(['-', ' '], '', trim($code)));
    }
}
