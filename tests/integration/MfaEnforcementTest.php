<?php

declare(strict_types=1);

use Esse\DB;
use Esse\RateLimit;
use Esse\Totp;

// Eigene, eindeutig benannte Helfer statt Wiederverwendung aus anderen *Test.php-Dateien (siehe
// Begruendung in EmailVerificationTest.php/AccountApprovalTest.php) — alle Testdateien laufen im
// selben globalen Scope.
function setMfaTestSetting(string $key, string $value): ?string
{
    $ts  = DB::table('settings');
    $old = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = ?", [$key]);
    DB::query(
        "INSERT INTO `{$ts}` (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
    return $old;
}

function restoreMfaTestSetting(string $key, ?string $old): void
{
    $ts = DB::table('settings');
    if ($old === null) {
        DB::delete($ts, ['key' => $key]);
    } else {
        DB::query("UPDATE `{$ts}` SET `value` = ? WHERE `key` = ?", [$old, $key]);
    }
}

// Errechnet den aktuell gueltigen TOTP-Code fuer ein Secret unabhaengig nach (RFC 6238), per
// Reflection auf die private Totp::codeAt()/base32Decode() — gleiches Muster wie tests/TotpTest.php.
function mfaCurrentTotpCode(string $secretBase32): string
{
    $ref    = new ReflectionClass(Totp::class);
    $decode = $ref->getMethod('base32Decode');
    $codeAt = $ref->getMethod('codeAt');
    $secret = $decode->invoke(null, $secretBase32);
    return $codeAt->invoke(null, $secret, (int) floor(time() / 30));
}

// Legt einen fuer die ersten beiden Gates (E-Mail-Verifikation, Admin-Freigabe) bereits
// konformen Test-Account an, der nur noch am neuen MFA-Gate haengen kann.
function makeMfaTestUser(string $email, string $password, bool $totpEnabled = false): int
{
    $tu = DB::table('users');
    DB::delete($tu, ['email' => $email]);
    $data = [
        'display_name'      => 'MFA Test',
        'email'              => $email,
        'password'           => password_hash($password, PASSWORD_BCRYPT),
        'role'               => 'member',
        'active'             => 1,
        'email_verified_at'  => date('Y-m-d H:i:s'),
        'approved_at'        => date('Y-m-d H:i:s'),
    ];
    if ($totpEnabled) {
        $secret = Totp::generateSecret();
        $data['totp_secret']  = \Esse\Crypto::encrypt($secret);
        $data['totp_enabled'] = 1;
    }
    return DB::insert($tu, $data);
}

return [
    'POST /login: Level 2fa, Account ohne TOTP/Passkey wird zur Einrichtung umgeleitet' => function (Http $http) {
        $oldLevel = setMfaTestSetting('mfa_enforcement_level', '2fa');
        $email    = 'mfa-setup-required-test@example.test';
        $password = 'TestPassword123';
        $tu       = DB::table('users');
        $tl       = DB::table('audit_log');
        $bucket   = 'login:127.0.0.1';

        try {
            makeMfaTestUser($email, $password);
            RateLimit::clear($bucket);

            $loginPage = $http->get('/login');
            $csrf      = extractCsrf($loginPage['body']);
            $res       = $http->post('/login', [
                '_csrf' => $csrf, '_form' => 'admin_login',
                'login' => $email, 'password' => $password,
            ]);

            Assert::same(302, $res['status']);
            $location = $res['headers']['location'][0] ?? '';
            Assert::true(str_starts_with($location, '/admin/setup-mfa'), "Redirect zu /admin/setup-mfa erwartet, war: {$location}");

            $rateLimitHits = (int) DB::value(
                "SELECT COUNT(*) FROM `" . DB::table('rate_limits') . "` WHERE bucket = ?",
                [$bucket]
            );
            Assert::same(0, $rateLimitHits, 'Login-Rate-Limit darf hier nicht erhoeht werden');

            $blockedCount = (int) DB::value(
                "SELECT COUNT(*) FROM `{$tl}` WHERE event = 'login_blocked_mfa_setup_required'"
            );
            Assert::true($blockedCount > 0, 'login_blocked_mfa_setup_required sollte protokolliert worden sein');
        } finally {
            RateLimit::clear($bucket);
            restoreMfaTestSetting('mfa_enforcement_level', $oldLevel);
            DB::delete($tu, ['email' => $email]);
        }
    },

    'TOTP-Einrichtung end-to-end ueber /admin/setup-mfa fuehrt zum Login' => function (Http $http) {
        $oldLevel = setMfaTestSetting('mfa_enforcement_level', '2fa');
        $email    = 'mfa-totp-setup-test@example.test';
        $password = 'TestPassword123';
        $tu       = DB::table('users');

        try {
            makeMfaTestUser($email, $password);

            $loginPage = $http->get('/login');
            $csrf      = extractCsrf($loginPage['body']);
            $http->post('/login', [
                '_csrf' => $csrf, '_form' => 'admin_login',
                'login' => $email, 'password' => $password,
            ]);

            $setupPage = $http->get('/admin/setup-mfa');
            Assert::same(200, $setupPage['status']);
            Assert::true(str_contains($setupPage['body'], 'TOTP einrichten'), 'TOTP-Option sollte bei Level 2fa angeboten werden');

            $startRes = $http->post('/admin/setup-mfa', ['_csrf' => $csrf, '_action' => 'mfa_setup_totp_start']);
            Assert::true(
                (bool) preg_match('/<code>([A-Z2-7]+)<\/code>/', $startRes['body'], $m),
                'TOTP-Secret sollte im HTML stehen'
            );
            $secret = $m[1];
            $code   = mfaCurrentTotpCode($secret);

            $confirmRes = $http->post('/admin/setup-mfa', [
                '_csrf' => $csrf, '_action' => 'mfa_setup_totp_confirm', 'code' => $code,
            ]);
            Assert::true(str_contains($confirmRes['body'], 'Weiter zum Login'), 'Backup-Codes + Weiter-Button erwartet');

            $user = DB::fetch("SELECT totp_enabled FROM `{$tu}` WHERE email = ?", [$email]);
            Assert::same(1, (int) $user['totp_enabled'], 'TOTP sollte nach Confirm aktiviert sein');

            $finishRes = $http->post('/admin/setup-mfa', ['_csrf' => $csrf, '_action' => 'mfa_setup_finish']);
            Assert::same(302, $finishRes['status'], 'Login sollte nach abgeschlossener Einrichtung erfolgen');
        } finally {
            restoreMfaTestSetting('mfa_enforcement_level', $oldLevel);
            DB::delete($tu, ['email' => $email]);
        }
    },

    'POST /login: Level passkey ignoriert vorhandenes TOTP, Account wird trotzdem zur Einrichtung umgeleitet' => function (Http $http) {
        $oldLevel = setMfaTestSetting('mfa_enforcement_level', 'passkey');
        $email    = 'mfa-passkey-required-test@example.test';
        $password = 'TestPassword123';
        $tu       = DB::table('users');

        try {
            makeMfaTestUser($email, $password, totpEnabled: true);

            $loginPage = $http->get('/login');
            $csrf      = extractCsrf($loginPage['body']);
            $res       = $http->post('/login', [
                '_csrf' => $csrf, '_form' => 'admin_login',
                'login' => $email, 'password' => $password,
            ]);

            Assert::same(302, $res['status']);
            $location = $res['headers']['location'][0] ?? '';
            Assert::true(str_starts_with($location, '/admin/setup-mfa'), "Trotz aktivem TOTP Redirect zu /admin/setup-mfa erwartet, war: {$location}");

            $setupPage = $http->get('/admin/setup-mfa');
            Assert::false(str_contains($setupPage['body'], 'TOTP einrichten'), 'Bei Level passkey darf keine TOTP-Option angeboten werden');
            Assert::true(str_contains($setupPage['body'], 'Passkey registrieren'), 'Passkey-Option erwartet');
        } finally {
            restoreMfaTestSetting('mfa_enforcement_level', $oldLevel);
            DB::delete($tu, ['email' => $email]);
        }
    },

    'POST /login: Schalter ausschalten entsperrt einen noch im Setup haengenden Account sofort' => function (Http $http) {
        $oldLevel = setMfaTestSetting('mfa_enforcement_level', '2fa');
        $email    = 'mfa-toggle-off-test@example.test';
        $password = 'TestPassword123';
        $tu       = DB::table('users');

        try {
            makeMfaTestUser($email, $password);

            $loginPage = $http->get('/login');
            $csrf      = extractCsrf($loginPage['body']);
            $http->post('/login', [
                '_csrf' => $csrf, '_form' => 'admin_login',
                'login' => $email, 'password' => $password,
            ]);

            setMfaTestSetting('mfa_enforcement_level', 'off');

            $res = $http->post('/login', [
                '_csrf' => $csrf, '_form' => 'admin_login',
                'login' => $email, 'password' => $password,
            ]);
            Assert::same(302, $res['status'], 'Login sollte funktionieren, sobald die Pflicht-Stufe ausgeschaltet ist');
        } finally {
            restoreMfaTestSetting('mfa_enforcement_level', $oldLevel);
            DB::delete($tu, ['email' => $email]);
        }
    },

    'POST /login: bereits TOTP-konformer Nutzer (Level 2fa) faellt auf das bestehende /admin/verify-2fa zurueck' => function (Http $http) {
        $oldLevel = setMfaTestSetting('mfa_enforcement_level', '2fa');
        $email    = 'mfa-already-compliant-test@example.test';
        $password = 'TestPassword123';
        $tu       = DB::table('users');

        try {
            makeMfaTestUser($email, $password, totpEnabled: true);

            $loginPage = $http->get('/login');
            $csrf      = extractCsrf($loginPage['body']);
            $res       = $http->post('/login', [
                '_csrf' => $csrf, '_form' => 'admin_login',
                'login' => $email, 'password' => $password,
            ]);

            Assert::same(302, $res['status']);
            $location = $res['headers']['location'][0] ?? '';
            Assert::true(str_starts_with($location, '/admin/verify-2fa'), "Regression: erwartet /admin/verify-2fa wie bisher, war: {$location}");
        } finally {
            restoreMfaTestSetting('mfa_enforcement_level', $oldLevel);
            DB::delete($tu, ['email' => $email]);
        }
    },

    'POST /admin/mfa-setup/passkey-options ohne Pending-Setup-Session erhaelt 403' => function (Http $http) {
        $res = $http->post('/admin/mfa-setup/passkey-options', []);
        Assert::same(403, $res['status']);
    },
];
