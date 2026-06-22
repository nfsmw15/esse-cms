<?php

declare(strict_types=1);

namespace Esse;

class Auth
{
    private static ?array $currentUser = null;
    private static bool $defaultsSynced = false;
    private static bool $securityMigrationsSynced = false;

    // Role hierarchy — index determines "power level" (higher = more access)
    public const ROLES = ['guest', 'member', 'author', 'editor', 'admin', 'forge'];

    public const PERMISSIONS = [
        'php_upload'      => ['PHP/HTML hochladen',     'Eigene PHP- und HTML-Dateien als Seiten hochladen'],
        'manage_users'    => ['Benutzer verwalten',      'Benutzer anlegen, bearbeiten und deaktivieren'],
        'manage_admins'   => ['Admins verwalten',        'Benutzer zur Admin- oder Forge-Rolle befördern'],
        'manage_plugins'  => ['Plugins verwalten',       'Plugins installieren, aktualisieren und entfernen'],
        'manage_themes'   => ['Themes verwalten',        'Themes installieren und wechseln'],
        'manage_repos'    => ['Repos verwalten',         'Plugin- und Theme-Repos hinzufügen und entfernen'],
        'manage_settings' => ['Einstellungen verwalten', 'Systemeinstellungen ändern'],
        'manage_backups'  => ['Backups verwalten',       'Backups erstellen, herunterladen und löschen — enthält vollständigen DB-Dump inkl. Zugangsdaten'],
        'manage_content'  => ['Inhalte verwalten',       'Seiten, Menüs und Inhalte verwalten'],
        'manage_files'    => ['Dateien verwalten',       'Dateien hochladen und verwalten'],
        'view_logs'       => ['Logs einsehen',           'System- und Zugriffslogs anzeigen'],
    ];

    public const DEFAULT_ROLE_PERMISSIONS = [
        'member' => [],
        'author' => ['manage_content', 'manage_files'],
        'editor' => ['manage_content', 'manage_files'],
        'admin'  => [
            'manage_users',
            'manage_admins',
            'manage_plugins',
            'manage_themes',
            'manage_repos',
            'manage_settings',
            'manage_content',
            'manage_files',
            'view_logs',
        ],
    ];

    // Must be called once at boot (after session_start is safe to call)
    public static function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => SecurityHeaders::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        self::syncSecurityMigrations();

        if (!isset($_SESSION['esse_uid'])) return;

        $t    = DB::table('users');
        $user = DB::fetch("SELECT * FROM `{$t}` WHERE id = ? AND active = 1", [$_SESSION['esse_uid']]);

        if (!$user) {
            // User deleted or deactivated — end session cleanly
            self::logout();
            return;
        }

        self::$currentUser = $user;
        self::syncDefaultPermissions();
    }

    // Attempt login with username or e-mail + password. Returns true on success.
    public static function attempt(string $email, string $password): bool
    {
        $t    = DB::table('users');
        $user = DB::fetch(
            "SELECT * FROM `{$t}` WHERE email = ? AND active = 1",
            [$email]
        );

        if (!$user || !password_verify($password, $user['password'])) {
            return false;
        }

        // Upgrade hash silently if bcrypt cost or algo changed
        if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
            $t = DB::table('users');
            DB::update($t, ['password' => password_hash($password, PASSWORD_BCRYPT)], ['id' => $user['id']]);
        }

        // Klassisches 2FA-Gate: TOTP ist ein zweiter Faktor zum Passwort — der Login ist
        // nach korrektem Passwort noch nicht abgeschlossen, sondern wartet auf den TOTP-/
        // Backup-Code-Schritt in admin/verify-2fa.php (Passkeys laufen unabhängig davon,
        // siehe WebAuthn::verifyPasswordlessAuth — die ersetzen Passwort UND TOTP).
        if (TwoFactor::isEnabled($user)) {
            $_SESSION['esse_2fa_uid'] = $user['id'];
            $_SESSION['esse_2fa_at']  = time();
            return false;
        }

        self::login($user);
        return true;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['esse_uid'] = $user['id'];
        self::$currentUser    = $user;
    }

    public static function logout(): void
    {
        self::$currentUser = null;
        unset($_SESSION['esse_uid']);
        session_regenerate_id(true);
    }

    // -- Status checks --

    public static function check(): bool  { return self::$currentUser !== null; }
    public static function guest(): bool  { return self::$currentUser === null; }
    public static function user(): ?array { return self::$currentUser; }
    public static function role(): string { return self::$currentUser['role'] ?? 'guest'; }
    public static function id(): ?int     { return self::$currentUser ? (int) self::$currentUser['id'] : null; }

    // Returns true if the current user's role is at least $required
    public static function meetsRole(string $required): bool
    {
        $current  = array_search(self::role(), self::ROLES, true);
        $required = array_search($required, self::ROLES, true);

        if ($current === false || $required === false) return false;
        return $current >= $required;
    }

    // Returns true if the current user has a specific permission.
    // Forge always passes. Other roles are checked against the DB.
    public static function can(string $permission): bool
    {
        if (self::role() === 'forge') return true;
        if (!self::$currentUser) return false;
        self::syncDefaultPermissions();

        $tp = DB::table('permissions');
        $tr = DB::table('roles');
        $trp = DB::table('role_permissions');

        // Check if the user's role grants this permission
        $result = DB::fetch(
            "SELECT 1
               FROM `{$trp}` rp
               JOIN `{$tr}` r  ON r.id  = rp.role_id
               JOIN `{$tp}` p  ON p.id  = rp.permission_id
              WHERE r.slug = ? AND p.slug = ?",
            [self::role(), $permission]
        );

        if ($result !== null) return true;

        // Check per-user permission override
        $tup = DB::table('user_permissions');
        $result = DB::fetch(
            "SELECT granted
               FROM `{$tup}`
              WHERE user_id = ? AND permission_slug = ?",
            [self::id(), $permission]
        );

        return $result !== null && (bool) $result['granted'];
    }

    public static function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::can($permission)) return true;
        }
        return false;
    }

    // Re-Auth-Gate für sicherheitsrelevante Aktionen (E-Mail/Passwort ändern, Passkeys verwalten).
    public static function verifyCurrentPassword(string $plain): bool
    {
        return self::$currentUser !== null && password_verify($plain, self::$currentUser['password']);
    }

    public static function syncDefaultPermissions(): void
    {
        if (self::$defaultsSynced) return;

        try {
            $tu  = DB::table('users');
            $tp  = DB::table('permissions');
            $tr  = DB::table('roles');
            $trp = DB::table('role_permissions');

            // Migrate role column from ENUM to VARCHAR once custom roles are supported.
            $roleColumn = DB::fetch("SHOW COLUMNS FROM `{$tu}` LIKE 'role'");
            if ($roleColumn && str_starts_with(strtolower((string)$roleColumn['Type']), 'enum(')) {
                DB::query("ALTER TABLE `{$tu}` MODIFY COLUMN `role` VARCHAR(50) NOT NULL DEFAULT 'member'");
            }

            foreach (self::PERMISSIONS as $slug => [$label, $description]) {
                DB::query(
                    "INSERT INTO `{$tp}` (slug, label, description) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description)",
                    [$slug, $label, $description]
                );
            }

            foreach (self::DEFAULT_ROLE_PERMISSIONS as $role => $permissions) {
                DB::query(
                    "INSERT INTO `{$tr}` (slug, label, is_default) VALUES (?, ?, 1)
                     ON DUPLICATE KEY UPDATE label = VALUES(label), is_default = 1",
                    [$role, ucfirst($role)]
                );

                // Only seed role permissions on first use (empty role).
                // After that the DB is authoritative — manual changes are never overwritten.
                $hasPerms = (int) DB::value(
                    "SELECT COUNT(*) FROM `{$trp}` rp
                       JOIN `{$tr}` r ON r.id = rp.role_id
                      WHERE r.slug = ?",
                    [$role]
                );
                if ($hasPerms === 0) {
                    foreach ($permissions as $permission) {
                        DB::query(
                            "INSERT IGNORE INTO `{$trp}` (role_id, permission_id)
                             SELECT r.id, p.id FROM `{$tr}` r, `{$tp}` p
                              WHERE r.slug = ? AND p.slug = ?",
                            [$role, $permission]
                        );
                    }
                }
            }
            PageVisibility::migrateDb();
            TwoFactor::migrateDb();
            AuditLog::migrateDb();
            Seo::migrateDb();
            UserFields::migrateDb();
            Media::migrateDb();
            self::$defaultsSynced = true;
        } catch (\Throwable) {
            // Installer or partial migrations may not have permission tables yet.
        }
    }

    private static function syncSecurityMigrations(): void
    {
        if (self::$securityMigrationsSynced) return;

        try {
            TwoFactor::migrateDb();
            RateLimit::migrateDb();
            self::$securityMigrationsSynced = true;
        } catch (\Throwable) {
            // Installer or partially configured databases may not have users/settings yet.
        }
    }

    // -- CSRF --

    public static function csrfToken(): string
    {
        if (empty($_SESSION['esse_csrf'])) {
            $_SESSION['esse_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['esse_csrf'];
    }

    // Call this at the start of every POST handler
    public static function verifyCsrf(): bool
    {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return isset($_SESSION['esse_csrf']) && hash_equals($_SESSION['esse_csrf'], $token);
    }
}
