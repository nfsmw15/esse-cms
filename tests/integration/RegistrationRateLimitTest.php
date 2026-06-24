<?php

declare(strict_types=1);

use Esse\DB;
use Esse\RateLimit;

// Registrierung muss fuer diese Tests aktiv sein, unabhaengig vom Ausgangszustand der
// Test-DB — Wert wird in jedem Test gesichert und danach zurueckgesetzt.
function withRegistrationEnabled(callable $fn): void
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
// Mindestzeit ab, bevor das Formular abgeschickt werden darf.
function solveCaptchaFromPage(string $html): string
{
    preg_match('/(\d+)\s*\+\s*(\d+)\s*=/', $html, $m);
    sleep(4);
    return (string) ((int) $m[1] + (int) $m[2]);
}

return [
    'POST /registrieren: IP-Rate-Limit blockiert nach zu vielen Versuchen' => function (Http $http) {
        withRegistrationEnabled(function () use ($http) {
            $tu = DB::table('users');
            $ipBucket = 'register:ip:127.0.0.1';
            RateLimit::clear($ipBucket);
            // Limit liegt bei 5 — 5 Treffer vorab simulieren, der naechste echte Versuch muss blockiert werden.
            for ($i = 0; $i < 5; $i++) RateLimit::hit($ipBucket);

            $email = 'rate-limit-ip-test@example.test';
            DB::delete($tu, ['email' => $email]);

            try {
                $page   = $http->get('/registrieren');
                $csrf   = extractCsrf($page['body']);
                $answer = solveCaptchaFromPage($page['body']);

                $res = $http->post('/registrieren', [
                    '_csrf' => $csrf, 'display_name' => 'RateLimitTest', 'email' => $email,
                    'password' => 'TestPassword123', 'password_confirm' => 'TestPassword123',
                    'captcha_answer' => $answer,
                ]);

                Assert::true(str_contains($res['body'], 'Zu viele Registrierungsversuche'), 'Sollte wegen IP-Rate-Limit blockiert werden');
                Assert::true(DB::fetch("SELECT id FROM `{$tu}` WHERE email = ?", [$email]) === null, 'Account darf trotz Rate-Limit nicht angelegt worden sein');
            } finally {
                RateLimit::clear($ipBucket);
                DB::delete($tu, ['email' => $email]);
            }
        });
    },

    'POST /registrieren: E-Mail-Rate-Limit blockiert wiederholte Versuche mit derselben Adresse' => function (Http $http) {
        withRegistrationEnabled(function () use ($http) {
            $tu = DB::table('users');
            $email = 'rate-limit-email-test@example.test';
            $emailBucket = 'register:email:' . strtolower($email);
            $ipBucket    = 'register:ip:127.0.0.1';
            RateLimit::clear($emailBucket);
            RateLimit::clear($ipBucket);
            // Limit liegt bei 3 — 3 Treffer vorab simulieren, der naechste echte Versuch muss blockiert werden.
            for ($i = 0; $i < 3; $i++) RateLimit::hit($emailBucket);

            DB::delete($tu, ['email' => $email]);

            try {
                $page   = $http->get('/registrieren');
                $csrf   = extractCsrf($page['body']);
                $answer = solveCaptchaFromPage($page['body']);

                $res = $http->post('/registrieren', [
                    '_csrf' => $csrf, 'display_name' => 'RateLimitTest', 'email' => $email,
                    'password' => 'TestPassword123', 'password_confirm' => 'TestPassword123',
                    'captcha_answer' => $answer,
                ]);

                Assert::true(str_contains($res['body'], 'Zu viele Registrierungsversuche'), 'Sollte wegen E-Mail-Rate-Limit blockiert werden');
                Assert::true(DB::fetch("SELECT id FROM `{$tu}` WHERE email = ?", [$email]) === null, 'Account darf trotz Rate-Limit nicht angelegt worden sein');
            } finally {
                RateLimit::clear($emailBucket);
                RateLimit::clear($ipBucket);
                DB::delete($tu, ['email' => $email]);
            }
        });
    },

    'POST /registrieren: regulaerer Versuch unterhalb des Limits funktioniert weiterhin' => function (Http $http) {
        withRegistrationEnabled(function () use ($http) {
            $tu = DB::table('users');
            $email = 'rate-limit-success-test@example.test';
            RateLimit::clear('register:ip:127.0.0.1');
            RateLimit::clear('register:email:' . strtolower($email));
            DB::delete($tu, ['email' => $email]);

            try {
                $page   = $http->get('/registrieren');
                $csrf   = extractCsrf($page['body']);
                $answer = solveCaptchaFromPage($page['body']);

                $res = $http->post('/registrieren', [
                    '_csrf' => $csrf, 'display_name' => 'RateLimitOk', 'email' => $email,
                    'password' => 'TestPassword123', 'password_confirm' => 'TestPassword123',
                    'captcha_answer' => $answer,
                ]);

                Assert::true(str_contains($res['body'], 'Account erstellt'), 'Regulaere Registrierung sollte weiterhin funktionieren');
                Assert::true(DB::fetch("SELECT id FROM `{$tu}` WHERE email = ?", [$email]) !== null, 'Account sollte angelegt worden sein');
            } finally {
                RateLimit::clear('register:ip:127.0.0.1');
                RateLimit::clear('register:email:' . strtolower($email));
                DB::delete($tu, ['email' => $email]);
            }
        });
    },
];
