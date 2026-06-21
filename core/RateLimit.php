<?php

declare(strict_types=1);

namespace Esse;

// IP-basierte Brute-Force-Bremse, persistiert in der DB statt in der Session —
// ein Angreifer kann die Sperre also nicht durch Verwerfen des Session-Cookies umgehen.
class RateLimit
{
    public static function migrateDb(): void
    {
        $t = DB::table('rate_limits');

        DB::query("CREATE TABLE IF NOT EXISTS `{$t}` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `bucket`     VARCHAR(190) NOT NULL,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_rate_limits_bucket` (`bucket`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // True, wenn fuer $bucket bereits $maxAttempts Treffer innerhalb der letzten
    // $windowSeconds Sekunden aufgezeichnet wurden.
    public static function tooMany(string $bucket, int $maxAttempts, int $windowSeconds): bool
    {
        $t = DB::table('rate_limits');
        $count = (int) DB::value(
            "SELECT COUNT(*) FROM `{$t}` WHERE bucket = ? AND created_at > (NOW() - INTERVAL ? SECOND)",
            [$bucket, $windowSeconds]
        );
        return $count >= $maxAttempts;
    }

    // Zaehlt einen Fehlversuch fuer $bucket.
    public static function hit(string $bucket): void
    {
        $t = DB::table('rate_limits');
        DB::insert($t, ['bucket' => $bucket]);

        // Gelegentliches Aufraeumen statt Cron — vermeidet zusaetzliche Infrastruktur.
        if (random_int(1, 50) === 1) {
            self::cleanup();
        }
    }

    // Loescht alle Treffer fuer $bucket, z.B. nach erfolgreichem Login.
    public static function clear(string $bucket): void
    {
        $t = DB::table('rate_limits');
        DB::delete($t, ['bucket' => $bucket]);
    }

    // Loescht Eintraege, die fuer kein sinnvolles Zeitfenster mehr relevant sein koennen.
    public static function cleanup(): void
    {
        $t = DB::table('rate_limits');
        DB::query("DELETE FROM `{$t}` WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    }

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
