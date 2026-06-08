<?php

declare(strict_types=1);

namespace Esse;

class PageVisibility
{
    public const VALUES = ['public', 'guest_only', 'registered', 'roles'];

    public const LABELS = [
        'public'     => 'Öffentlich',
        'guest_only' => 'Nur Gäste',
        'registered' => 'Alle Eingeloggten',
        'roles'      => 'Rollen',
    ];

    // ── Normalise legacy values ───────────────────────────────────────────────

    public static function normalize(string $vis): string
    {
        return match ($vis) {
            'members' => 'registered',
            'admin'   => 'roles',
            default   => in_array($vis, self::VALUES, true) ? $vis : 'public',
        };
    }

    // ── Read visibility ───────────────────────────────────────────────────────

    // For a CMS page (page row from DB).
    public static function forCmsPage(array $page): string
    {
        return self::normalize($page['visibility'] ?? 'public');
    }

    // For a plugin or standard page (override table, fallback to plugin default).
    public static function forPage(string $slug, string $pluginDefault = 'public'): string
    {
        $slug = ltrim($slug, '/');
        $tv   = DB::table('page_visibility');
        $row  = DB::value("SELECT `visibility` FROM `{$tv}` WHERE `slug` = ?", [$slug]);
        return $row !== null ? self::normalize((string) $row) : self::normalize($pluginDefault);
    }

    // Icon override for plugin/standard pages (returns override or pluginDefault).
    public static function getIcon(string $slug, string $pluginDefault = ''): string
    {
        $slug = ltrim($slug, '/');
        $tv   = DB::table('page_visibility');
        try {
            $icon = DB::value("SELECT `icon` FROM `{$tv}` WHERE `slug` = ?", [$slug]);
            return ($icon !== null && $icon !== '') ? (string) $icon : $pluginDefault;
        } catch (\Exception) {
            return $pluginDefault;
        }
    }

    // Strip 'bi-' / 'bi bi-' prefix from a plugin-registered icon class to get bare name.
    public static function stripIconPrefix(string $icon): string
    {
        return (string) preg_replace('/^(bi\s+)?bi-/', '', $icon);
    }

    // Allowed roles for a slug (used when visibility = 'roles').
    public static function getRoles(string $slug): array
    {
        $slug = ltrim($slug, '/');
        $tr   = DB::table('page_roles');
        $rows = DB::fetchAll("SELECT `role_slug` FROM `{$tr}` WHERE `page_slug` = ?", [$slug]);
        return array_column($rows, 'role_slug');
    }

    // ── Access check ─────────────────────────────────────────────────────────

    public static function check(string $slug, string $visibility): bool
    {
        return match ($visibility) {
            'public'     => true,
            'guest_only' => !Auth::check(),
            'registered' => Auth::check(),
            'roles'      => self::checkRoles(ltrim($slug, '/')),
            default      => true,
        };
    }

    private static function checkRoles(string $slug): bool
    {
        if (!Auth::check()) return false;
        if (Auth::role() === 'forge') return true;
        return in_array(Auth::role(), self::getRoles($slug), true);
    }

    // ── Save visibility ───────────────────────────────────────────────────────

    // CMS page: updates esse_pages.visibility + esse_page_roles.
    public static function saveCmsPage(string $slug, string $visibility, array $roles = []): void
    {
        $slug = ltrim($slug, '/');
        $tp   = DB::table('pages');
        DB::query("UPDATE `{$tp}` SET `visibility` = ? WHERE `slug` = ?", [$visibility, $slug]);
        self::saveRoles($slug, $visibility, $roles);
    }

    // Plugin/standard page: updates esse_page_visibility + esse_page_roles.
    public static function savePage(string $slug, string $visibility, array $roles = []): void
    {
        $slug = ltrim($slug, '/');
        $tv   = DB::table('page_visibility');
        DB::query(
            "INSERT INTO `{$tv}` (`slug`, `visibility`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `visibility` = VALUES(`visibility`)",
            [$slug, $visibility]
        );
        self::saveRoles($slug, $visibility, $roles);
    }

    // Save icon override for a plugin/standard page.
    public static function saveIcon(string $slug, ?string $icon): void
    {
        $slug = ltrim($slug, '/');
        $tv   = DB::table('page_visibility');
        DB::query(
            "INSERT INTO `{$tv}` (`slug`, `visibility`, `icon`) VALUES (?, 'public', ?)
             ON DUPLICATE KEY UPDATE `icon` = VALUES(`icon`)",
            [$slug, $icon]
        );
    }

    private static function saveRoles(string $slug, string $visibility, array $roles): void
    {
        $tr = DB::table('page_roles');
        DB::query("DELETE FROM `{$tr}` WHERE `page_slug` = ?", [$slug]);
        if ($visibility !== 'roles') return;
        foreach ($roles as $role) {
            $role = preg_replace('/[^a-z0-9\-]/', '', (string) $role);
            if ($role) {
                DB::query(
                    "INSERT IGNORE INTO `{$tr}` (`page_slug`, `role_slug`) VALUES (?, ?)",
                    [$slug, $role]
                );
            }
        }
    }

    // ── DB migration (called once from Auth::syncDefaultPermissions) ──────────

    public static function migrateDb(): void
    {
        $tp = DB::table('pages');
        $tr = DB::table('page_roles');
        $tv = DB::table('page_visibility');

        DB::query("CREATE TABLE IF NOT EXISTS `{$tr}` (
            `page_slug` VARCHAR(200) NOT NULL,
            `role_slug` VARCHAR(50)  NOT NULL,
            PRIMARY KEY (`page_slug`, `role_slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        DB::query("CREATE TABLE IF NOT EXISTS `{$tv}` (
            `slug`       VARCHAR(200) NOT NULL,
            `visibility` VARCHAR(20)  NOT NULL DEFAULT 'public',
            `icon`       VARCHAR(100) NULL,
            PRIMARY KEY (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::ensureVisibilityColumn($tp);
        self::ensureVisibilityColumn($tv);

        // Add icon column to existing installations
        $cols = DB::fetchAll("SHOW COLUMNS FROM `{$tv}`");
        if (!in_array('icon', array_column($cols, 'Field'), true)) {
            DB::query("ALTER TABLE `{$tv}` ADD COLUMN `icon` VARCHAR(100) NULL");
        }

        // Migrate legacy 'members' → 'registered'
        DB::query("UPDATE `{$tp}` SET `visibility` = 'registered' WHERE `visibility` = 'members'");

        // Migrate legacy 'admin' → 'roles' + seed esse_page_roles
        $adminPages = DB::fetchAll("SELECT `slug` FROM `{$tp}` WHERE `visibility` = 'admin'");
        foreach ($adminPages as $row) {
            DB::query(
                "INSERT IGNORE INTO `{$tr}` (`page_slug`, `role_slug`) VALUES (?, 'admin')",
                [$row['slug']]
            );
        }
        DB::query("UPDATE `{$tp}` SET `visibility` = 'roles' WHERE `visibility` = 'admin'");
    }

    private static function ensureVisibilityColumn(string $table): void
    {
        $cols = DB::fetchAll("SHOW COLUMNS FROM `{$table}` LIKE 'visibility'");
        if (!$cols) {
            return;
        }

        $type = strtolower((string) ($cols[0]['Type'] ?? ''));
        $null = strtoupper((string) ($cols[0]['Null'] ?? ''));
        if ($type === 'varchar(20)' && $null === 'NO') {
            return;
        }

        DB::query("ALTER TABLE `{$table}` MODIFY COLUMN `visibility` VARCHAR(20) NOT NULL DEFAULT 'public'");
    }
}
