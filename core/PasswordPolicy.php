<?php

declare(strict_types=1);

namespace Esse;

// Admin-konfigurierbare Passwort-Mindestanforderungen. Zwei Modi: "custom" (Mindestlaenge +
// Anzahl gefordertet Zeichenklassen) und "bsi" (offizielle BSI-Empfehlung, drei Stufen: kurz+
// komplex, lang+einfach, oder kurz+weniger komplex bei bereits aktiver 2FA/Passkey).
class PasswordPolicy
{
    // Reine Logik, kein DB-Zugriff — direkt unit-testbar. $maxSequential gilt nur im
    // "custom"-Modus (0 = aus) — der BSI-Modus bildet bewusst nur die offizielle Empfehlung ab.
    public static function check(string $password, string $mode, int $minLength, int $minClasses, bool $hasMfa = false, int $maxSequential = 0): array
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
        if ($maxSequential > 0 && self::hasLongSequential($password, $maxSequential)) {
            $errors[] = "Passwort darf nicht mehr als {$maxSequential} aufeinanderfolgende Buchstaben oder Zahlen enthalten.";
        }
        return $errors;
    }

    // DB-Fassade fuer die Call-Sites. $forUserId: bekannter Account fuer den BSI-MFA-Bonus und
    // die Passwort-Historie (null bei Registrierung/Installer, wo es noch keinen Account gibt).
    public static function validate(string $password, ?int $forUserId = null): array
    {
        $cfg = self::clientConfig($forUserId);
        $errors = self::check($password, $cfg['mode'], $cfg['minLength'], $cfg['minClasses'], $cfg['hasMfa'], $cfg['maxSequential']);

        // Historie ist wie das Sequenz-Limit nur im "custom"-Modus wirksam (siehe Hinweistext in
        // admin/settings.php) — der BSI-Modus bildet bewusst nur die offizielle Empfehlung ab.
        if ($cfg['mode'] !== 'bsi' && $forUserId !== null && $cfg['historyCount'] > 0
            && self::matchesRecentHistory($password, $forUserId, $cfg['historyCount'])
        ) {
            $errors[] = "Passwort darf nicht mit einem der letzten {$cfg['historyCount']} Passwörter übereinstimmen.";
        }

        return $errors;
    }

    // Aktuelle Richtlinie als Array, z.B. fuer die Live-Checkliste im Frontend (JSON-Export).
    // $forUserId: bekannter Account fuer den BSI-MFA-Bonus (null bei Registrierung/Installer).
    public static function clientConfig(?int $forUserId = null): array
    {
        $ts = DB::table('settings');
        return [
            'mode'          => DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'password_policy_mode'") ?: 'custom',
            'minLength'     => (int) (DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'password_min_length'") ?: 10),
            'minClasses'    => (int) (DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'password_min_classes'") ?: 3),
            'maxSequential' => (int) (DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'password_max_sequential'") ?: 0),
            'historyCount'  => (int) (DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'password_history_count'") ?: 0),
            'hasMfa'        => $forUserId !== null && self::accountHasMfa($forUserId),
        ];
    }

    // Schreibt den noch aktuellen (gleich abgeloesten) Hash in die Historie — vom jeweiligen
    // Call-Site VOR dem Ueberschreiben von users.password aufgerufen. Laeuft unconditional bei
    // jeder Aenderung (nicht nur wenn historyCount>0), damit beim spaeteren Einschalten der
    // Pruefung bereits ein Verlauf vorhanden ist. Begrenzt auf 50 Eintraege/Nutzer, unabhaengig
    // von der aktuell konfigurierten Anzahl (Schutz vor unbegrenztem Wachstum bei Settings-
    // Aenderungen).
    public static function recordHistory(int $userId, string $oldHash): void
    {
        $th = DB::table('password_history');
        DB::insert($th, ['user_id' => $userId, 'password' => $oldHash]);
        DB::query(
            "DELETE FROM `{$th}` WHERE user_id = ? AND id NOT IN (
                SELECT id FROM (SELECT id FROM `{$th}` WHERE user_id = ? ORDER BY created_at DESC LIMIT 50) t
            )",
            [$userId, $userId]
        );
    }

    private static function matchesRecentHistory(string $password, int $userId, int $count): bool
    {
        $tu = DB::table('users');
        $current = DB::value("SELECT password FROM `{$tu}` WHERE id = ?", [$userId]);
        if ($current && password_verify($password, $current)) return true;

        if ($count > 1) {
            $th = DB::table('password_history');
            $rows = DB::fetchAll(
                "SELECT password FROM `{$th}` WHERE user_id = ? ORDER BY created_at DESC LIMIT " . ($count - 1),
                [$userId]
            );
            foreach ($rows as $row) {
                if (password_verify($password, $row['password'])) return true;
            }
        }
        return false;
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

    // Erkennt Laeufe von mehr als $maxRun aufeinanderfolgenden (auf- oder absteigenden) Zeichen
    // derselben Klasse (z.B. "abcd", "4321"). Ein Richtungswechsel bricht den Lauf bewusst ab —
    // "abcba" zaehlt nicht als 4er-Lauf, nur als zwei 3er-Laeufe ("abc"/"cba").
    private static function hasLongSequential(string $password, int $maxRun): bool
    {
        $chars = str_split($password);
        $run = 1;
        $dir = 0; // 1 = aufsteigend, -1 = absteigend, 0 = noch keiner
        for ($i = 1; $i < count($chars); $i++) {
            $sameClass = self::charClass($chars[$i - 1]) !== null && self::charClass($chars[$i - 1]) === self::charClass($chars[$i]);
            $delta  = $sameClass ? (ord($chars[$i]) - ord($chars[$i - 1])) : 0;
            $curDir = $delta === 1 ? 1 : ($delta === -1 ? -1 : 0);

            if ($curDir !== 0 && $curDir === $dir) {
                $run++;
            } elseif ($curDir !== 0) {
                $run = 2;
                $dir = $curDir;
            } else {
                $run = 1;
                $dir = 0;
            }
            if ($run > $maxRun) return true;
        }
        return false;
    }

    private static function charClass(string $c): ?string
    {
        if (ctype_lower($c)) return 'lower';
        if (ctype_upper($c)) return 'upper';
        if (ctype_digit($c)) return 'digit';
        return null;
    }

    private static function accountHasMfa(int $userId): bool
    {
        $tu   = DB::table('users');
        $user = DB::fetch("SELECT totp_enabled FROM `{$tu}` WHERE id = ?", [$userId]);
        if ($user && !empty($user['totp_enabled'])) return true;
        return !empty(WebAuthn::credentialsForUser($userId));
    }
}
