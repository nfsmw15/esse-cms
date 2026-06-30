<?php

declare(strict_types=1);

namespace Esse;

class EmailVerification
{
    // 24h statt der 1h beim Passwort-Reset — eine unbestaetigte Mail-Adresse ist weniger
    // zeitkritisch, Nutzer pruefen ihren Posteingang oft erst Stunden spaeter.
    public const TTL_SECONDS = 86400;

    // Loescht bestehende Tokens des Nutzers und legt einen neuen an. Gibt den Token zurueck.
    public static function createToken(int $userId, string $email): string
    {
        $tv = DB::table('email_verifications');
        DB::delete($tv, ['user_id' => $userId]);
        $token = bin2hex(random_bytes(32));
        DB::insert($tv, ['token' => $token, 'user_id' => $userId, 'email' => $email]);
        return $token;
    }

    public static function sendMail(string $email, string $displayName, string $token): void
    {
        $ts       = DB::table('settings');
        $siteUrl  = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'site_url'") ?? '';
        $siteName = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'site_name'") ?? 'ESSE CMS';
        $verifyUrl = rtrim($siteUrl, '/') . '/email-bestaetigen?token=' . $token;

        try {
            Mailer::send(
                $email,
                $displayName,
                'E-Mail-Adresse bestätigen — ' . $siteName,
                "<p>Hallo {$displayName},</p>"
                . "<p>bitte bestätige deine E-Mail-Adresse, um dein Konto zu aktivieren.</p>"
                . "<p><a href=\"{$verifyUrl}\">E-Mail-Adresse bestätigen</a></p>"
                . "<p>Der Link ist 24 Stunden gültig.</p>"
                . "<p>Falls du kein Konto erstellt hast, kannst du diese E-Mail ignorieren.</p>"
                . "<p>— {$siteName}</p>"
            );
        } catch (\Throwable $e) {
            // Fehler loggen, aber nicht an den Nutzer durchreichen (gleiches Muster wie
            // admin/forgot-password.php).
            error_log('ESSE Mailer error: ' . $e->getMessage());
        }
    }
}
