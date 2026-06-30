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
        'manage_updates'  => ['Updates verwalten',        'CMS-Updates prüfen und einspielen — erstellt automatisch ein Backup und verändert Code/Dateien'],
        'manage_content'  => ['Inhalte verwalten',       'Seiten, Menüs und Inhalte verwalten'],
        'manage_files'    => ['Dateien verwalten',       'Dateien hochladen und verwalten'],
        'view_logs'       => ['Logs einsehen',           'System- und Zugriffslogs anzeigen'],
    ];

    // Diese Permissions dürfen nur von Forge selbst vergeben/entzogen werden (an Rollen oder
    // einzelne Nutzer) — manage_admins reicht dafür nicht, sonst kann sich ein Admin nahe an
    // Forge heranziehen (Code-Ausführung, Backup-Zugriff, Update-Auslösung, Plugin-/Theme-
    // Vertrauensgrenze über Repo-Kanäle).
    public const FORGE_ONLY_PERMISSIONS = ['php_upload', 'manage_backups', 'manage_updates', 'manage_repos'];

    public const DEFAULT_ROLE_PERMISSIONS = [
        'member' => [],
        'author' => ['manage_content', 'manage_files'],
        'editor' => ['manage_content', 'manage_files'],
        'admin'  => [
            'manage_users',
            'manage_admins',
            'manage_plugins',
            'manage_themes',
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

        // Passwort wurde nach dem Login dieser Session geändert (z.B. in einer anderen Session
        // oder per Admin/Reset) — diese Session ist damit veraltet und wird beendet.
        if (!empty($user['password_changed_at'])) {
            $loginAt = (int) ($_SESSION['esse_login_at'] ?? 0);
            if ($loginAt < strtotime($user['password_changed_at'])) {
                self::logout();
                return;
            }
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

        // E-Mail-Verifizierungs-Gate: greift nur fuer Self-Registrierungen (admin-angelegte und
        // der Installer-Forge-Account bekommen email_verified_at sofort gesetzt). Bewusst vor
        // dem 2FA-Gate, da TOTP erst nach dem ersten erfolgreichen Login eingerichtet werden
        // kann — fuer einen unverifizierten Account ist der 2FA-Branch ohnehin nie relevant,
        // aber "Account ueberhaupt nutzbar" ist die logisch vorgelagerte Bedingung. Kein
        // RateLimit::hit()/login_failed-Audit hier — admin/login.php unterscheidet diesen Fall
        // ueber das Session-Flag, genau wie es das beim 2FA-Fall schon tut.
        if (empty($user['email_verified_at'])) {
            $_SESSION['esse_unverified_email'] = $user['email'];
            return false;
        }

        // Admin-Freigabe-Gate (optional, per Settings-Schalter): greift nur, wenn die
        // Einstellung aktuell aktiv ist — bewusst die LIVE Einstellung pruefen statt nur die
        // Spalte, damit das Ausschalten der Pflicht-Freigabe bereits wartende Accounts sofort
        // entsperrt, ohne dass jemand sie einzeln nachtraeglich freigeben muss.
        $ts = DB::table('settings');
        if (DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'registration_requires_approval'") === '1'
            && empty($user['approved_at'])
        ) {
            $_SESSION['esse_pending_approval'] = $user['email'];
            return false;
        }

        // Pflicht-2FA/Passkey-Gate (optional, per Settings-Schalter mit drei Stufen): greift nur,
        // wenn der Account die geforderte Stufe noch nicht erfuellt. Bewusst VOR dem klassischen
        // TOTP-Verifikations-Gate — bei Stufe "passkey" reicht vorhandenes TOTP nicht, der Nutzer
        // muss trotzdem zur Einrichtung. Faellt dieser Block durch (Account ist bereits konform),
        // greift das bestehende TOTP-Gate unten unveraendert weiter.
        $mfaLevel = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'mfa_enforcement_level'") ?: 'off';
        if ($mfaLevel !== 'off') {
            $hasPasskey = !empty(WebAuthn::credentialsForUser((int) $user['id']));
            $compliant  = $mfaLevel === 'passkey' ? $hasPasskey : ($hasPasskey || TwoFactor::isEnabled($user));
            if (!$compliant) {
                $_SESSION['esse_mfa_setup_uid']   = $user['id'];
                $_SESSION['esse_mfa_setup_at']    = time();
                $_SESSION['esse_mfa_setup_level'] = $mfaLevel;
                return false;
            }
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
        $_SESSION['esse_uid']      = $user['id'];
        $_SESSION['esse_login_at'] = time();
        self::$currentUser         = $user;
    }

    public static function logout(): void
    {
        self::$currentUser = null;
        unset($_SESSION['esse_uid'], $_SESSION['esse_login_at']);
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

            $tu   = DB::table('users');
            $cols = array_column(DB::fetchAll("SHOW COLUMNS FROM `{$tu}`"), 'Field');
            if (!in_array('password_changed_at', $cols, true)) {
                DB::query("ALTER TABLE `{$tu}` ADD COLUMN `password_changed_at` DATETIME NULL");
            }
            if (!in_array('email_verified_at', $cols, true)) {
                DB::query("ALTER TABLE `{$tu}` ADD COLUMN `email_verified_at` DATETIME NULL");
                // Bestandsnutzer duerfen durch diese neue Spalte nicht rueckwirkend ausgesperrt
                // werden — nur die zum Zeitpunkt des ALTER bereits existierenden NULL-Zeilen
                // werden befuellt, dieser Block laeuft (per $cols-Gate oben) nur einmalig.
                DB::query("UPDATE `{$tu}` SET `email_verified_at` = NOW() WHERE `email_verified_at` IS NULL");
            }
            if (!in_array('approved_at', $cols, true)) {
                DB::query("ALTER TABLE `{$tu}` ADD COLUMN `approved_at` DATETIME NULL");
                DB::query("UPDATE `{$tu}` SET `approved_at` = NOW() WHERE `approved_at` IS NULL");
            }

            // email_verifications-Tabelle nachziehen (Bestandsinstallationen vor dieser Funktion
            // haben sie noch nicht). CREATE TABLE IF NOT EXISTS ist idempotent, daher unkritisch,
            // dass dieser Loop bei jedem Request erneut laeuft (gleiches Muster wie repo_channels
            // unten).
            $pPrefix = defined('ESSE_DB_PREFIX') ? \ESSE_DB_PREFIX : 'esse_';
            foreach (Schema::tables($pPrefix) as $sql) {
                if (str_contains($sql, "`{$pPrefix}email_verifications`")) {
                    DB::query($sql);
                }
            }

            // Einmalige Bereinigung: manage_repos wurde früher per DEFAULT_ROLE_PERMISSIONS an
            // admin vergeben, ist seit der FORGE_ONLY_PERMISSIONS-Härtung davon aber bewusst
            // ausgenommen. Bestehende Zuweisungen einmalig entfernen, statt sie unbegrenzt
            // wiederherzustellen — danach kann Forge die Permission über /admin/roles jederzeit
            // erneut bewusst vergeben, ohne dass diese Migration sie wieder entzieht. Lebt
            // bewusst hier (statt in syncDefaultPermissions()), da diese Methode auch ohne
            // eingeloggten Nutzer bei jedem Request läuft — der Sicherheitsfix soll nicht erst
            // beim nächsten Admin-Login greifen.
            $ts          = DB::table('settings');
            $tp          = DB::table('permissions');
            $tr          = DB::table('roles');
            $trp         = DB::table('role_permissions');
            $cleanupDone = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'migrated_manage_repos_admin_cleanup'");
            if (!$cleanupDone) {
                DB::query(
                    "DELETE rp FROM `{$trp}` rp
                       JOIN `{$tr}` r ON r.id = rp.role_id
                       JOIN `{$tp}` p ON p.id = rp.permission_id
                      WHERE r.slug = 'admin' AND p.slug = 'manage_repos'"
                );
                DB::query(
                    "INSERT INTO `{$ts}` (`key`, `value`) VALUES ('migrated_manage_repos_admin_cleanup', '1')
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
                );
            }

            // repo_channels-Tabelle nachziehen (geteilte GitHub-Repo-Kanäle für Plugins, Themes
            // und Icon-Packs). Bestandsinstallationen hatten dafür die plugin-spezifische
            // plugin_repos-Tabelle — einmalig umbenennen statt eine zweite, leere Tabelle
            // anzulegen und die bestehenden Kanäle (z.B. den offiziellen nfsmw15-Kanal) zu
            // verlieren. Lebt hier (statt in syncDefaultPermissions()), damit der Wechsel auch
            // ohne eingeloggten Nutzer sofort wirkt — sonst würde admin/plugins.php auf einer
            // laufenden Instanz bis zum nächsten Admin-Login auf die alte Tabelle zugreifen.
            $p   = defined('ESSE_DB_PREFIX') ? \ESSE_DB_PREFIX : 'esse_';
            $trc = "{$p}repo_channels";
            $oldExists = DB::fetchAll("SHOW TABLES LIKE '{$p}plugin_repos'");
            $newExists = DB::fetchAll("SHOW TABLES LIKE '{$trc}'");
            if ($oldExists && !$newExists) {
                DB::query("RENAME TABLE `{$p}plugin_repos` TO `{$trc}`");
            }
            foreach (Schema::tables($p) as $sql) {
                if (str_contains($sql, "`{$trc}`")) {
                    DB::query($sql);
                }
            }
            // Frische Installationen starten mit einer leeren Kanal-Liste — den offiziellen
            // ESSE-Kanal automatisch eintragen, damit "Verfügbar" sofort etwas anzeigt.
            if ((int) DB::value("SELECT COUNT(*) FROM `{$trc}`") === 0) {
                DB::query(
                    "INSERT INTO `{$trc}` (owner, label, trusted) VALUES ('nfsmw15', 'ESSE CMS Official', 1)"
                );
            }

            // Als "privat" markierte Mediendateien, die noch unter /public/uploads liegen
            // (Altlast vor dieser Härtung), in den geschützten Speicherort verschieben. Lebt hier
            // statt in syncDefaultPermissions(), damit die bisher öffentlich erreichbaren Dateien
            // nicht erst beim nächsten Admin-Login geschützt werden.
            Media::migratePrivateFiles();

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
        $ok    = isset($_SESSION['esse_csrf']) && hash_equals($_SESSION['esse_csrf'], $token);

        if (!$ok) {
            AuditLog::record('csrf_failed', self::id(), self::user()['email'] ?? null, [
                'path'    => $_SERVER['REQUEST_URI']    ?? null,
                'method'  => $_SERVER['REQUEST_METHOD'] ?? null,
                '_action' => $_POST['_action']          ?? null,
            ]);
        }

        return $ok;
    }
}
