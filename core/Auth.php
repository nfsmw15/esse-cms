<?php

declare(strict_types=1);

namespace Esse;

class Auth
{
    private static ?array $currentUser = null;

    // Role hierarchy — index determines "power level" (higher = more access)
    public const ROLES = ['guest', 'member', 'author', 'editor', 'admin', 'forge'];

    // Must be called once at boot (after session_start is safe to call)
    public static function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        if (!isset($_SESSION['esse_uid'])) return;

        $t    = DB::table('users');
        $user = DB::fetch("SELECT * FROM `{$t}` WHERE id = ? AND active = 1", [$_SESSION['esse_uid']]);

        if (!$user) {
            // User deleted or deactivated — end session cleanly
            self::logout();
            return;
        }

        self::$currentUser = $user;
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
