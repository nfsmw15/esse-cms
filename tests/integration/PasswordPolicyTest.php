<?php

declare(strict_types=1);

use Esse\Crypto;
use Esse\DB;
use Esse\Totp;

// Eigene, eindeutig benannte Helfer statt Wiederverwendung aus anderen *Test.php-Dateien (siehe
// Begruendung in den vorherigen Feature-Tests — gemeinsamer globaler Scope ueber alle Testdateien).
function setPasswordPolicyTestSetting(string $key, string $value): ?string
{
    $ts  = DB::table('settings');
    $old = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = ?", [$key]);
    DB::query(
        "INSERT INTO `{$ts}` (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
    return $old;
}

function restorePasswordPolicyTestSetting(string $key, ?string $old): void
{
    $ts = DB::table('settings');
    if ($old === null) {
        DB::delete($ts, ['key' => $key]);
    } else {
        DB::query("UPDATE `{$ts}` SET `value` = ? WHERE `key` = ?", [$old, $key]);
    }
}

function solvePasswordPolicyCaptcha(string $html): string
{
    preg_match('/(\d+)\s*\+\s*(\d+)\s*=/', $html, $m);
    sleep(4);
    return (string) ((int) $m[1] + (int) $m[2]);
}

// Registriert mit gegebenem Passwort und gibt den Response-Body zurueck. Account wird vor dem
// Versuch geloescht (idempotent bei Wiederholung).
function attemptPasswordPolicyRegistration(Http $http, string $email, string $password): array
{
    $tu = DB::table('users');
    DB::delete($tu, ['email' => $email]);

    $page   = $http->get('/registrieren');
    $csrf   = extractCsrf($page['body']);
    $answer = solvePasswordPolicyCaptcha($page['body']);

    return $http->post('/registrieren', [
        '_csrf' => $csrf, 'display_name' => 'Policy Test', 'email' => $email,
        'password' => $password, 'password_confirm' => $password,
        'captcha_answer' => $answer,
    ]);
}

return [
    'POST /registrieren: password_min_classes=4 lehnt "TestPassword123" ab (nur 3 Klassen)' => function (Http $http) {
        $oldReg     = setPasswordPolicyTestSetting('registration_enabled', '1');
        $oldClasses = setPasswordPolicyTestSetting('password_min_classes', '4');
        $email = 'pwpolicy-classes4-test@example.test';
        $tu    = DB::table('users');

        try {
            $res = attemptPasswordPolicyRegistration($http, $email, 'TestPassword123');
            Assert::true(str_contains($res['body'], 'mindestens 4 der folgenden Kategorien'), 'Fehlermeldung wegen Zeichenklassen erwartet');
            Assert::true(DB::fetch("SELECT id FROM `{$tu}` WHERE email = ?", [$email]) === null, 'Account darf nicht angelegt worden sein');
        } finally {
            restorePasswordPolicyTestSetting('registration_enabled', $oldReg);
            restorePasswordPolicyTestSetting('password_min_classes', $oldClasses);
            DB::delete($tu, ['email' => $email]);
        }
    },

    'POST /registrieren: Default-Richtlinie (minClasses=3) akzeptiert "TestPassword123" weiterhin' => function (Http $http) {
        $oldReg = setPasswordPolicyTestSetting('registration_enabled', '1');
        $email  = 'pwpolicy-default-test@example.test';
        $tu     = DB::table('users');

        try {
            $res = attemptPasswordPolicyRegistration($http, $email, 'TestPassword123');
            Assert::true(str_contains($res['body'], 'Account erstellt'), 'Regulaere Registrierung sollte mit Default-Richtlinie weiterhin funktionieren');
            Assert::true(DB::fetch("SELECT id FROM `{$tu}` WHERE email = ?", [$email]) !== null, 'Account sollte angelegt worden sein');
        } finally {
            restorePasswordPolicyTestSetting('registration_enabled', $oldReg);
            DB::delete($tu, ['email' => $email]);
        }
    },

    'POST /registrieren: BSI-Modus lehnt kurzes 2-Klassen-Passwort ab, akzeptiert 25-Zeichen-Passwort' => function (Http $http) {
        $oldReg  = setPasswordPolicyTestSetting('registration_enabled', '1');
        $oldMode = setPasswordPolicyTestSetting('password_policy_mode', 'bsi');
        $emailShort = 'pwpolicy-bsi-short-test@example.test';
        $emailLong  = 'pwpolicy-bsi-long-test@example.test';
        $tu = DB::table('users');

        try {
            $resShort = attemptPasswordPolicyRegistration($http, $emailShort, 'abcdefgh1'); // 2 Klassen, 9 Zeichen
            Assert::true(str_contains($resShort['body'], 'BSI-Empfehlung'), 'BSI-Fehlermeldung erwartet');
            Assert::true(DB::fetch("SELECT id FROM `{$tu}` WHERE email = ?", [$emailShort]) === null, 'Account darf nicht angelegt worden sein');

            $longPassword = str_repeat('a', 30); // 30 Zeichen, nur eine Zeichenklasse — Stufe 2 (lang+einfach)
            $resLong = attemptPasswordPolicyRegistration($http, $emailLong, $longPassword);
            Assert::true(str_contains($resLong['body'], 'Account erstellt'), 'Langes, einfaches Passwort sollte unter BSI-Stufe 2 akzeptiert werden');
            Assert::true(DB::fetch("SELECT id FROM `{$tu}` WHERE email = ?", [$emailLong]) !== null, 'Account sollte angelegt worden sein');
        } finally {
            restorePasswordPolicyTestSetting('registration_enabled', $oldReg);
            restorePasswordPolicyTestSetting('password_policy_mode', $oldMode);
            DB::delete($tu, ['email' => $emailShort]);
            DB::delete($tu, ['email' => $emailLong]);
        }
    },

    'POST /profil: BSI-Modus erlaubt kurzes 3-Klassen-Passwort, wenn der Account bereits 2FA hat' => function (Http $http) {
        $oldMode = setPasswordPolicyTestSetting('password_policy_mode', 'bsi');
        $email    = 'pwpolicy-mfa-bonus-test@example.test';
        $password = 'TestPassword123';
        $tu       = DB::table('users');

        try {
            DB::delete($tu, ['email' => $email]);
            $userId = DB::insert($tu, [
                'display_name'      => 'Policy MFA Test',
                'email'              => $email,
                'password'           => password_hash($password, PASSWORD_BCRYPT),
                'role'               => 'member',
                'active'             => 1,
                'email_verified_at'  => date('Y-m-d H:i:s'),
                'approved_at'        => date('Y-m-d H:i:s'),
            ]);

            // Erst normal einloggen (noch ohne TOTP, damit das bestehende 2FA-Verifikations-Gate
            // beim Login nicht greift), TOTP danach direkt in der DB aktivieren — die laufende
            // Session bleibt davon unberuehrt.
            $loginPage = $http->get('/login');
            $csrf      = extractCsrf($loginPage['body']);
            $loginRes  = $http->post('/login', [
                '_csrf' => $csrf, '_form' => 'admin_login',
                'login' => $email, 'password' => $password,
            ]);
            Assert::same(302, $loginRes['status'], 'Login sollte funktionieren');

            DB::update($tu, [
                'totp_enabled' => 1,
                'totp_secret'  => Crypto::encrypt(Totp::generateSecret()),
            ], ['id' => $userId]);

            $profilPage = $http->get('/profil');
            $profilCsrf = extractCsrf($profilPage['body']);

            $newPassword = 'Abcdefg1'; // 8 Zeichen, 3 Klassen — unter BSI nur mit MFA-Bonus gueltig
            $res = $http->post('/profil', [
                '_csrf' => $profilCsrf, '_action' => 'update_profile',
                'display_name' => 'Policy MFA Test', 'email' => $email,
                'password' => $newPassword, 'password_confirm' => $newPassword,
                'confirm_password' => $password,
            ]);

            Assert::true(str_contains($res['body'], 'Profil gespeichert'), 'Passwort-Aenderung sollte dank MFA-Bonus akzeptiert werden');

            $user = DB::fetch("SELECT password FROM `{$tu}` WHERE id = ?", [$userId]);
            Assert::true(password_verify($newPassword, $user['password']), 'Neues Passwort sollte gespeichert worden sein');
        } finally {
            restorePasswordPolicyTestSetting('password_policy_mode', $oldMode);
            DB::delete($tu, ['email' => $email]);
        }
    },
];
