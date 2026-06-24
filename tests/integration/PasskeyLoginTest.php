<?php

declare(strict_types=1);

use Esse\DB;

function passkeyFailedCount(): int
{
    $tl = DB::table('audit_log');
    return (int) DB::value("SELECT COUNT(*) FROM `{$tl}` WHERE event = 'passkey_login_failed'");
}

return [
    // Das Rate-Limit ist IP-basiert (core/RateLimit.php), nicht session-basiert — alle Requests
    // im Testlauf kommen von derselben IP (127.0.0.1). Beide Endpunkte werden deshalb in einem
    // Test zusammen geprueft statt in getrennten, da sie sich sonst gegenseitig den
    // Bucket-Zaehler beeinflussen wuerden, je nach Ausfuehrungsreihenfolge.
    'POST /admin/passkey/auth-verify: nach 10 Fehlversuchen greift das Rate-Limit (429) und blockt auch auth-options; darueber keine weiteren Audit-Log-Eintraege' => function (Http $http) {
        $csrf = extractCsrf($http->get('/login')['body']);
        $before = passkeyFailedCount();

        for ($i = 0; $i < 10; $i++) {
            $res = $http->postJson('/admin/passkey/auth-verify', $csrf, [
                'credential' => ['id' => 'AAAA', 'rawId' => 'AAAA', 'response' => []],
            ]);
            Assert::same(200, $res['status'], "Versuch " . ($i + 1) . " sollte noch nicht geblockt sein");
            $data = json_decode($res['body'], true);
            Assert::true(($data['error'] ?? '') !== '', "Versuch " . ($i + 1) . " sollte fehlschlagen (unbekannte Credential-ID)");
        }

        Assert::same($before + 10, passkeyFailedCount(), '10 Fehlversuche sollten 10 passkey_login_failed-Eintraege erzeugen');

        $blockedVerify = $http->postJson('/admin/passkey/auth-verify', $csrf, [
            'credential' => ['id' => 'AAAA', 'rawId' => 'AAAA', 'response' => []],
        ]);
        Assert::same(429, $blockedVerify['status'], '11. Versuch innerhalb des Fensters sollte das Rate-Limit treffen');
        Assert::same($before + 10, passkeyFailedCount(), 'Oberhalb des Limits darf kein weiterer Audit-Log-Eintrag entstehen');

        $blockedOptions = $http->postJson('/admin/passkey/auth-options', $csrf, []);
        Assert::same(429, $blockedOptions['status'], 'auth-options teilt sich den Bucket mit auth-verify (gleiche IP) und ist ebenfalls blockiert');
    },

    'POST /admin/passkey/auth-verify: ohne CSRF-Token wird abgelehnt' => function (Http $http) {
        $res = $http->postJson('/admin/passkey/auth-verify', 'ungueltiger-token', [
            'credential' => ['id' => 'CCCC', 'rawId' => 'CCCC', 'response' => []],
        ]);
        Assert::same(403, $res['status']);
    },
];
