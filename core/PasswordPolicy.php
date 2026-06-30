<?php

declare(strict_types=1);

namespace Esse;

// Admin-konfigurierbare Passwort-Mindestanforderungen. Zwei Modi: "custom" (Mindestlaenge +
// Anzahl gefordertet Zeichenklassen) und "bsi" (offizielle BSI-Empfehlung, drei Stufen: kurz+
// komplex, lang+einfach, oder kurz+weniger komplex bei bereits aktiver 2FA/Passkey).
class PasswordPolicy
{
    // Reine Logik, kein DB-Zugriff — direkt unit-testbar.
    public static function check(string $password, string $mode, int $minLength, int $minClasses, bool $hasMfa = false): array
    {
        $length  = strlen($password);
        $classes = self::countClasses($password);

        if ($mode === 'bsi') {
            $compliant =
                ($length >= 25)                                  // lang + einfach
                || ($length >= 8 && $classes >= 4)                // kurz + alle 4 Klassen
                || ($hasMfa && $length >= 8 && $classes >= 3);    // kurz + 3 Klassen, mit aktiver 2FA/Passkey

            if ($compliant) return [];

            return ['Passwort erfüllt nicht die BSI-Empfehlung: mindestens 8 Zeichen mit allen 4 Zeichenarten '
                . '(Groß-/Kleinbuchstaben, Ziffern, Sonderzeichen), oder mindestens 25 Zeichen lang'
                . ($hasMfa ? ', oder mindestens 8 Zeichen mit 3 Zeichenarten (dank aktiver 2FA/Passkey).' : '.')];
        }

        $errors = [];
        if ($length < $minLength) {
            $errors[] = "Passwort muss mindestens {$minLength} Zeichen haben.";
        }
        if ($minClasses > 1 && $classes < $minClasses) {
            $errors[] = "Passwort muss Zeichen aus mindestens {$minClasses} der folgenden Kategorien enthalten: "
                . "Großbuchstaben, Kleinbuchstaben, Ziffern, Sonderzeichen.";
        }
        return $errors;
    }

    // DB-Fassade fuer die Call-Sites. $forUserId: bekannter Account fuer den BSI-MFA-Bonus
    // (null bei Registrierung/Installer, wo es noch keinen Account gibt).
    public static function validate(string $password, ?int $forUserId = null): array
    {
        $ts = DB::table('settings');
        $mode       = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'password_policy_mode'") ?: 'custom';
        $minLength  = (int) (DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'password_min_length'") ?: 10);
        $minClasses = (int) (DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'password_min_classes'") ?: 3);
        $hasMfa     = $forUserId !== null && self::accountHasMfa($forUserId);

        return self::check($password, $mode, $minLength, $minClasses, $hasMfa);
    }

    private static function countClasses(string $password): int
    {
        $classes = 0;
        if (preg_match('/[a-z]/', $password)) $classes++;
        if (preg_match('/[A-Z]/', $password)) $classes++;
        if (preg_match('/[0-9]/', $password)) $classes++;
        if (preg_match('/[^a-zA-Z0-9]/', $password)) $classes++;
        return $classes;
    }

    private static function accountHasMfa(int $userId): bool
    {
        $tu   = DB::table('users');
        $user = DB::fetch("SELECT totp_enabled FROM `{$tu}` WHERE id = ?", [$userId]);
        if ($user && !empty($user['totp_enabled'])) return true;
        return !empty(WebAuthn::credentialsForUser($userId));
    }
}
