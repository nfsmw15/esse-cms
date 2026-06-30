<?php

declare(strict_types=1);

use Esse\DB;
use Esse\RateLimit;

// Schaltet registration_enabled fuer die Dauer von $fn auf '1', stellt den vorherigen Wert
// danach wieder her. Eigener Name (statt withRegistrationEnabled() aus
// RegistrationRateLimitTest.php), da alle tests/integration/*Test.php-Dateien per require() in
// denselben globalen Scope geladen werden — ein zweites function withRegistrationEnabled()
// waere ein Fatal Error "Cannot redeclare".
function enableRegistrationDuring(callable $fn): void
{
    $ts  = DB::table('settings');
    $old = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'registration_enabled'");
    DB::query(
        "INSERT INTO `{$ts}` (`key`, `value`) VALUES ('registration_enabled', '1')
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
    );
    try {
        $fn();
    } finally {
        if ($old === null) {
            DB::delete($ts, ['key' => 'registration_enabled']);
        } else {
            DB::query("UPDATE `{$ts}` SET `value` = ? WHERE `key` = 'registration_enabled'", [$old]);
        }
    }
}

// Loest den Mathe-Captcha aus der gerenderten Seite und wartet die Captcha::MIN_SECONDS (3s)
// Mindestzeit ab. Eigener Name, gleicher Grund wie oben (Kollision mit solveCaptchaFromPage()).
function solveRegisterCaptcha(string $html): string
{
    preg_match('/(\d+)\s*\+\s*(\d+)\s*=/', $html, $m);
    sleep(4);
    return (string) ((int) $m[1] + (int) $m[2]);
}

// Legt einen Verifikations-Token an (optional mit abweichendem created_at, um Ablauf zu
// simulieren) und gibt den Token zurueck. Mirror von makeResetToken() in PasswordResetTest.php.
function makeVerificationToken(int $userId, string $email, ?string $createdAt = null): string
{
    $tv    = DB::table('email_verifications');
    $token = bin2hex(random_bytes(32));
    DB::delete($tv, ['user_id' => $userId]);
    DB::insert($tv, [
        'token'      => $token,
        'user_id'    => $userId,
        'email'      => $email,
        'created_at' => $createdAt ?? date('Y-m-d H:i:s'),
    ]);
    return $token;
}

// Registriert einen frischen, unverifizierten Test-Account und gibt dessen User-Row zurueck.
function registerUnverifiedAccount(Http $http, string $email, string $password): array
{
    $tu = DB::table('users');
    DB::delete($tu, ['email' => $email]);

    $page   = $http->get('/registrieren');
    $csrf   = extractCsrf($page['body']);
    $answer = solveRegisterCaptcha($page['body']);

    $res = $http->post('/registrieren', [
        '_csrf' => $csrf, 'display_name' => 'Verify Test', 'email' => $email,
        'password' => $password, 'password_confirm' => $password,
        'captcha_answer' => $answer,
    ]);

    if (!str_contains($res['body'], 'Account erstellt')) {
        throw new \RuntimeException('Registrierung fuer Verify-Test fehlgeschlagen');
    }

    return DB::fetch("SELECT * FROM `{$tu}` WHERE email = ?", [$email]);
}

return [
    'POST /registrieren: erfolgreiche Registrierung erzeugt unverifizierten Account + Token' => function (Http $http) {
        enableRegistrationDuring(function () use ($http) {
            $email = 'verify-register-test@example.test';
            $tu    = DB::table('users');
            $tv    = DB::table('email_verifications');

            try {
                $user = registerUnverifiedAccount($http, $email, 'TestPassword123');

                Assert::true($user !== null, 'Account sollte angelegt worden sein');
                Assert::true($user['email_verified_at'] === null, 'email_verified_at sollte direkt nach Registrierung NULL sein');

                $verification = DB::fetch("SELECT * FROM `{$tv}` WHERE user_id = ?", [$user['id']]);
                Assert::true($verification !== null, 'Es sollte ein Verifikations-Token angelegt worden sein');
            } finally {
                DB::delete($tu, ['email' => $email]);
            }
        });
    },

    'POST /login: unverifizierter Account wird blockiert, kein Rate-Limit-Hit, kein login_failed' => function (Http $http) {
        enableRegistrationDuring(function () use ($http) {
            $email    = 'verify-login-blocked-test@example.test';
            $password = 'TestPassword123';
            $tu       = DB::table('users');
            $tl       = DB::table('audit_log');
            $bucket   = 'login:127.0.0.1';

            try {
                registerUnverifiedAccount($http, $email, $password);
                RateLimit::clear($bucket);

                $loginPage = $http->get('/login');
                $csrf      = extractCsrf($loginPage['body']);
                $res       = $http->post('/login', [
                    '_csrf' => $csrf, '_form' => 'admin_login',
                    'login' => $email, 'password' => $password,
                ]);

                Assert::same(200, $res['status'], 'Login sollte trotz korrektem Passwort nicht zum Redirect fuehren');
                Assert::true(
                    str_contains($res['body'], 'Bitte bestätige zuerst deine E-Mail-Adresse'),
                    'Hinweis auf fehlende Verifikation erwartet'
                );

                $rateLimitHits = (int) DB::value(
                    "SELECT COUNT(*) FROM `" . DB::table('rate_limits') . "` WHERE bucket = ?",
                    [$bucket]
                );
                Assert::same(0, $rateLimitHits, 'Login-Rate-Limit darf bei unverifiziertem Account nicht erhoeht werden');

                $failedCount = (int) DB::value(
                    "SELECT COUNT(*) FROM `{$tl}` WHERE event = 'login_failed' AND email = ?",
                    [$email]
                );
                Assert::same(0, $failedCount, 'login_failed darf fuer diesen Fall nicht protokolliert werden');

                $blockedCount = (int) DB::value(
                    "SELECT COUNT(*) FROM `{$tl}` WHERE event = 'login_blocked_unverified' AND email = ?",
                    [$email]
                );
                Assert::true($blockedCount > 0, 'login_blocked_unverified sollte protokolliert worden sein');
            } finally {
                RateLimit::clear($bucket);
                DB::delete($tu, ['email' => $email]);
            }
        });
    },

    'GET /admin/verify-email: ungueltiger Token zeigt Fehlermeldung' => function (Http $http) {
        $res = $http->get('/admin/verify-email?token=ungueltig');

        Assert::same(200, $res['status']);
        Assert::true(str_contains($res['body'], 'ungültig oder abgelaufen'), 'Fehlermeldung fuer ungueltigen Token erwartet');
    },

    'GET /admin/verify-email: abgelaufener Token wird abgelehnt und geloescht' => function (Http $http) {
        $email = 'verify-expired-test@example.test';
        $tu    = DB::table('users');
        $tv    = DB::table('email_verifications');

        try {
            $userId = DB::insert($tu, [
                'display_name' => 'Verify Expired', 'email' => $email,
                'password' => password_hash('TestPassword123', PASSWORD_BCRYPT), 'role' => 'member',
            ]);
            $token = makeVerificationToken($userId, $email, date('Y-m-d H:i:s', time() - 86500));

            $res = $http->get('/admin/verify-email?token=' . $token);

            Assert::same(200, $res['status']);
            Assert::true(str_contains($res['body'], 'ungültig oder abgelaufen'), 'Fehlermeldung fuer abgelaufenen Token erwartet');

            $row = DB::fetch("SELECT * FROM `{$tv}` WHERE token = ?", [$token]);
            Assert::true($row === null, 'Abgelaufener Token sollte aus der DB geloescht werden');
        } finally {
            DB::delete($tu, ['email' => $email]);
        }
    },

    'GET /admin/verify-email: gueltiger Token verifiziert Account, danach Login moeglich, Token nicht wiederverwendbar' => function (Http $http) {
        $email    = 'verify-success-test@example.test';
        $password = 'TestPassword123';
        $tu       = DB::table('users');
        $tv       = DB::table('email_verifications');

        try {
            $userId = DB::insert($tu, [
                'display_name' => 'Verify Success', 'email' => $email,
                'password' => password_hash($password, PASSWORD_BCRYPT), 'role' => 'member',
            ]);
            $token = makeVerificationToken($userId, $email);

            $res = $http->get('/admin/verify-email?token=' . $token);
            Assert::same(200, $res['status']);
            Assert::true(str_contains($res['body'], 'bestätigt'), 'Erfolgsmeldung erwartet');

            $user = DB::fetch("SELECT * FROM `{$tu}` WHERE id = ?", [$userId]);
            Assert::true($user['email_verified_at'] !== null, 'email_verified_at sollte gesetzt sein');

            $row = DB::fetch("SELECT * FROM `{$tv}` WHERE token = ?", [$token]);
            Assert::true($row === null, 'Token sollte nach Verwendung geloescht sein');

            // Token darf nicht erneut nutzbar sein (es existiert nicht mehr -> "ungueltig").
            $res2 = $http->get('/admin/verify-email?token=' . $token);
            Assert::true(str_contains($res2['body'], 'ungültig oder abgelaufen'), 'Token sollte nach Verwendung ungueltig sein');

            // Login funktioniert jetzt.
            $loginPage = $http->get('/login');
            $csrf      = extractCsrf($loginPage['body']);
            $loginRes  = $http->post('/login', [
                '_csrf' => $csrf, '_form' => 'admin_login',
                'login' => $email, 'password' => $password,
            ]);
            Assert::same(302, $loginRes['status'], 'Login sollte nach Verifikation funktionieren');
        } finally {
            DB::delete($tu, ['email' => $email]);
        }
    },

    'POST /admin/verify-email: Resend fuer unverifizierten Account erzeugt neuen Token' => function (Http $http) {
        $email = 'verify-resend-test@example.test';
        $tu    = DB::table('users');
        $tv    = DB::table('email_verifications');
        $bucket = 'email_verify_request:127.0.0.1';

        try {
            $userId = DB::insert($tu, [
                'display_name' => 'Verify Resend', 'email' => $email,
                'password' => password_hash('TestPassword123', PASSWORD_BCRYPT), 'role' => 'member',
            ]);
            $oldToken = makeVerificationToken($userId, $email);
            RateLimit::clear($bucket);

            $page = $http->get('/admin/verify-email');
            $csrf = extractCsrf($page['body']);

            $res = $http->post('/admin/verify-email', [
                '_csrf' => $csrf, 'email' => $email,
                'captcha_answer' => solveRegisterCaptcha($page['body']),
            ]);

            Assert::same(200, $res['status']);
            Assert::true(str_contains($res['body'], 'Posteingang'), 'Generische Erfolgsmeldung erwartet');

            $rows = DB::fetchAll("SELECT * FROM `{$tv}` WHERE user_id = ?", [$userId]);
            Assert::same(1, count($rows), 'Es sollte genau ein (neuer) Token existieren, kein Duplikat');
            Assert::false($rows[0]['token'] === $oldToken, 'Der alte Token sollte durch einen neuen ersetzt worden sein');
        } finally {
            RateLimit::clear($bucket);
            DB::delete($tu, ['email' => $email]);
        }
    },

    'POST /admin/verify-email: Resend fuer bereits verifizierten Account bleibt wirkungslos (Anti-Enumeration)' => function (Http $http) {
        $bucket = 'email_verify_request:127.0.0.1';
        RateLimit::clear($bucket);

        $tl     = DB::table('audit_log');
        $before = (int) DB::value("SELECT COUNT(*) FROM `{$tl}` WHERE event = 'email_verification_resent'");

        try {
            $page = $http->get('/admin/verify-email');
            $csrf = extractCsrf($page['body']);

            // TEST_MEMBER_EMAIL ist laut bootstrap.php seed() bereits verifiziert.
            $res = $http->post('/admin/verify-email', [
                '_csrf' => $csrf, 'email' => TEST_MEMBER_EMAIL,
                'captcha_answer' => solveRegisterCaptcha($page['body']),
            ]);

            Assert::same(200, $res['status']);
            Assert::true(str_contains($res['body'], 'Posteingang'), 'Generische Erfolgsmeldung auch fuer bereits verifizierten Account erwartet');

            $after = (int) DB::value("SELECT COUNT(*) FROM `{$tl}` WHERE event = 'email_verification_resent'");
            Assert::same($before, $after, 'Fuer einen bereits verifizierten Account darf kein Resend-Event protokolliert werden');
        } finally {
            RateLimit::clear($bucket);
        }
    },

    'GET /admin/verify-email: wiederholte ungueltige Tokens werden ab dem Limit nicht mehr geloggt' => function (Http $http) {
        $bucket = 'verify_email_view:127.0.0.1';
        RateLimit::clear($bucket);

        $tl     = DB::table('audit_log');
        $before = (int) DB::value("SELECT COUNT(*) FROM `{$tl}` WHERE event = 'email_verification_invalid_token'");

        try {
            // 20 ist das aktuelle Limit (siehe admin/verify-email.php).
            for ($i = 0; $i < 25; $i++) {
                $http->get('/admin/verify-email?token=rate-limit-spam-test-' . $i);
            }

            $after    = (int) DB::value("SELECT COUNT(*) FROM `{$tl}` WHERE event = 'email_verification_invalid_token'");
            $newCount = $after - $before;
            Assert::true($newCount < 25, "Es sollten nicht alle 25 Versuche geloggt worden sein, waren aber {$newCount}");
            Assert::true($newCount > 0, 'Die ersten Versuche unterhalb des Limits sollten weiterhin geloggt werden');
        } finally {
            RateLimit::clear($bucket);
        }
    },
];
