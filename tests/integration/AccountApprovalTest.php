<?php

declare(strict_types=1);

use Esse\DB;
use Esse\RateLimit;

// Setzt einen Settings-Key fuer die Testdauer und gibt den vorherigen Wert zurueck (zum
// Wiederherstellen via restoreApprovalTestSetting()). Eigene, eindeutig benannte Helfer statt
// Wiederverwendung aus anderen *Test.php-Dateien — alle Testdateien werden per require() in
// denselben globalen Scope geladen, und "AccountApprovalTest.php" laedt alphabetisch vor
// "EmailVerificationTest.php"/"RegistrationRateLimitTest.php", deren Helfer waeren zur
// Ausfuehrungszeit dieser Closures noch nicht deklariert.
function setApprovalTestSetting(string $key, string $value): ?string
{
    $ts  = DB::table('settings');
    $old = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = ?", [$key]);
    DB::query(
        "INSERT INTO `{$ts}` (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
    return $old;
}

function restoreApprovalTestSetting(string $key, ?string $old): void
{
    $ts = DB::table('settings');
    if ($old === null) {
        DB::delete($ts, ['key' => $key]);
    } else {
        DB::query("UPDATE `{$ts}` SET `value` = ? WHERE `key` = ?", [$old, $key]);
    }
}

function solveApprovalCaptcha(string $html): string
{
    preg_match('/(\d+)\s*\+\s*(\d+)\s*=/', $html, $m);
    sleep(4);
    return (string) ((int) $m[1] + (int) $m[2]);
}

// Legt einen e-mail-verifizierten, aber noch nicht freigegebenen Test-Account direkt an (ohne
// den vollen Registrierungs-Flow durchzuspielen) und gibt dessen ID zurueck.
function makePendingApprovalAccount(string $email, string $password): int
{
    $tu = DB::table('users');
    DB::delete($tu, ['email' => $email]);
    return DB::insert($tu, [
        'display_name'      => 'Pending Approval',
        'email'              => $email,
        'password'           => password_hash($password, PASSWORD_BCRYPT),
        'role'               => 'member',
        'active'             => 1,
        'email_verified_at'  => date('Y-m-d H:i:s'),
    ]);
}

return [
    'POST /registrieren: bei aktivierter Admin-Freigabe bleibt approved_at NULL' => function (Http $http) {
        $oldReg      = setApprovalTestSetting('registration_enabled', '1');
        $oldApproval = setApprovalTestSetting('registration_requires_approval', '1');
        $email = 'approval-register-test@example.test';
        $tu    = DB::table('users');
        DB::delete($tu, ['email' => $email]);

        try {
            $page   = $http->get('/registrieren');
            $csrf   = extractCsrf($page['body']);
            $answer = solveApprovalCaptcha($page['body']);

            $res = $http->post('/registrieren', [
                '_csrf' => $csrf, 'display_name' => 'Approval Test', 'email' => $email,
                'password' => 'TestPassword123', 'password_confirm' => 'TestPassword123',
                'captcha_answer' => $answer,
            ]);

            Assert::true(str_contains($res['body'], 'Account erstellt'), 'Registrierung sollte trotzdem erfolgreich sein');

            $user = DB::fetch("SELECT * FROM `{$tu}` WHERE email = ?", [$email]);
            Assert::true($user !== null, 'Account sollte angelegt worden sein');
            Assert::true($user['approved_at'] === null, 'approved_at sollte bei aktiver Pflicht-Freigabe NULL sein');
        } finally {
            restoreApprovalTestSetting('registration_enabled', $oldReg);
            restoreApprovalTestSetting('registration_requires_approval', $oldApproval);
            DB::delete($tu, ['email' => $email]);
        }
    },

    'POST /login: verifizierter aber nicht freigegebener Account wird blockiert, kein Rate-Limit-Hit, kein login_failed' => function (Http $http) {
        $oldApproval = setApprovalTestSetting('registration_requires_approval', '1');
        $email    = 'approval-login-blocked-test@example.test';
        $password = 'TestPassword123';
        $tu       = DB::table('users');
        $tl       = DB::table('audit_log');
        $bucket   = 'login:127.0.0.1';

        try {
            makePendingApprovalAccount($email, $password);
            RateLimit::clear($bucket);

            $loginPage = $http->get('/login');
            $csrf      = extractCsrf($loginPage['body']);
            $res       = $http->post('/login', [
                '_csrf' => $csrf, '_form' => 'admin_login',
                'login' => $email, 'password' => $password,
            ]);

            Assert::same(200, $res['status'], 'Login sollte trotz korrektem Passwort nicht zum Redirect fuehren');
            Assert::true(
                str_contains($res['body'], 'wartet auf Freigabe'),
                'Hinweis auf fehlende Freigabe erwartet'
            );

            $rateLimitHits = (int) DB::value(
                "SELECT COUNT(*) FROM `" . DB::table('rate_limits') . "` WHERE bucket = ?",
                [$bucket]
            );
            Assert::same(0, $rateLimitHits, 'Login-Rate-Limit darf bei wartendem Account nicht erhoeht werden');

            $failedCount = (int) DB::value(
                "SELECT COUNT(*) FROM `{$tl}` WHERE event = 'login_failed' AND email = ?",
                [$email]
            );
            Assert::same(0, $failedCount, 'login_failed darf fuer diesen Fall nicht protokolliert werden');

            $blockedCount = (int) DB::value(
                "SELECT COUNT(*) FROM `{$tl}` WHERE event = 'login_blocked_pending_approval' AND email = ?",
                [$email]
            );
            Assert::true($blockedCount > 0, 'login_blocked_pending_approval sollte protokolliert worden sein');
        } finally {
            RateLimit::clear($bucket);
            restoreApprovalTestSetting('registration_requires_approval', $oldApproval);
            DB::delete($tu, ['email' => $email]);
        }
    },

    'POST /admin/users (_action=approve_user): Forge gibt Account frei, danach Login moeglich' => function (Http $http) {
        $oldApproval = setApprovalTestSetting('registration_requires_approval', '1');
        $email    = 'approval-approve-test@example.test';
        $password = 'TestPassword123';
        $tu       = DB::table('users');
        $tl       = DB::table('audit_log');

        try {
            $userId = makePendingApprovalAccount($email, $password);

            loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
            $listPage = $http->get('/admin/users');
            $csrf     = extractCsrf($listPage['body']);

            $res = $http->post('/admin/users', [
                '_csrf' => $csrf, '_action' => 'approve_user', 'user_id' => $userId,
            ]);

            Assert::same(200, $res['status']);
            $data = json_decode($res['body'], true);
            Assert::true(($data['success'] ?? false) === true, 'JSON-Erfolg erwartet');

            $user = DB::fetch("SELECT approved_at FROM `{$tu}` WHERE id = ?", [$userId]);
            Assert::true($user['approved_at'] !== null, 'approved_at sollte gesetzt sein');

            $approvedCount = (int) DB::value(
                "SELECT COUNT(*) FROM `{$tl}` WHERE event = 'user_approved' AND details LIKE ?",
                ['%"target_user_id":' . $userId . '%']
            );
            Assert::true($approvedCount > 0, 'user_approved sollte protokolliert worden sein');

            // Login mit frischem Client (der bisherige traegt die Forge-Session).
            $guest    = new Http(TEST_BASE_URL);
            $loginPage = $guest->get('/login');
            $loginCsrf = extractCsrf($loginPage['body']);
            $loginRes  = $guest->post('/login', [
                '_csrf' => $loginCsrf, '_form' => 'admin_login',
                'login' => $email, 'password' => $password,
            ]);
            Assert::same(302, $loginRes['status'], 'Login sollte nach Freigabe funktionieren');
        } finally {
            restoreApprovalTestSetting('registration_requires_approval', $oldApproval);
            DB::delete($tu, ['email' => $email]);
        }
    },

    'POST /login: Schalter ausschalten entsperrt einen noch wartenden Account sofort' => function (Http $http) {
        $oldApproval = setApprovalTestSetting('registration_requires_approval', '1');
        $email    = 'approval-toggle-off-test@example.test';
        $password = 'TestPassword123';
        $tu       = DB::table('users');

        try {
            makePendingApprovalAccount($email, $password);

            // Schalter wird ausgeschaltet, OHNE den Account einzeln freizugeben.
            restoreApprovalTestSetting('registration_requires_approval', '0');

            $loginPage = $http->get('/login');
            $csrf      = extractCsrf($loginPage['body']);
            $res       = $http->post('/login', [
                '_csrf' => $csrf, '_form' => 'admin_login',
                'login' => $email, 'password' => $password,
            ]);

            Assert::same(302, $res['status'], 'Login sollte funktionieren, sobald die Pflicht-Freigabe ausgeschaltet ist');

            $user = DB::fetch("SELECT approved_at FROM `{$tu}` WHERE email = ?", [$email]);
            Assert::true($user['approved_at'] === null, 'approved_at bleibt NULL, nur die Einstellung entsperrt den Login');
        } finally {
            restoreApprovalTestSetting('registration_requires_approval', $oldApproval);
            DB::delete($tu, ['email' => $email]);
        }
    },

    'GET/POST /admin/users: Member ohne manage_users erhaelt 403' => function (Http $http) {
        loginAs($http, TEST_MEMBER_EMAIL, TEST_MEMBER_PASSWORD);

        $getRes = $http->get('/admin/users');
        Assert::same(403, $getRes['status']);

        $postRes = $http->post('/admin/users', ['_action' => 'approve_user', 'user_id' => 1]);
        Assert::same(403, $postRes['status']);
    },
];
