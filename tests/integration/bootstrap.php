<?php

declare(strict_types=1);

// Setzt die esse_test-Datenbank auf einen sauberen, geseedeten Zustand zurueck und
// schreibt eine Test-config.php. Wird von tests/integration/run.php eingebunden,
// kann aber auch direkt aufgerufen werden: php tests/integration/bootstrap.php

defined('ESSE_ROOT') || define('ESSE_ROOT', dirname(__DIR__, 2));

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Esse\\')) {
        $path = ESSE_ROOT . '/core/' . str_replace('\\', '/', substr($class, 5)) . '.php';
        if (file_exists($path)) require_once $path;
    }
});

use Esse\Auth;
use Esse\Schema;

const TEST_DB_HOST   = '127.0.0.1';
const TEST_DB_PORT   = 3306;
const TEST_DB_NAME   = 'esse_test';
const TEST_DB_USER   = 'esse_test';
const TEST_DB_PASS   = 'esse_test';
const TEST_DB_PREFIX = 'esse_';

const TEST_CONFIG_DIR = '/tmp/esse-test-config';
const TEST_BASE_URL   = 'http://127.0.0.1:8089';

// Feste Test-Accounts, von den Integrationstests verwendet.
const TEST_FORGE_EMAIL     = 'forge@example.test';
const TEST_FORGE_PASSWORD  = 'Forge-Test-Pass1';
const TEST_MEMBER_EMAIL    = 'member@example.test';
const TEST_MEMBER_PASSWORD = 'Member-Test-Pass1';

function testPdo(): \PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', TEST_DB_HOST, TEST_DB_PORT, TEST_DB_NAME);
    return new \PDO($dsn, TEST_DB_USER, TEST_DB_PASS, [
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
}

function resetDatabase(\PDO $pdo): void
{
    $p = TEST_DB_PREFIX;

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query("SHOW TABLES LIKE '{$p}%'")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
        $pdo->exec("DROP TABLE `{$table}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    foreach (Schema::tables($p) as $sql) {
        $pdo->exec($sql);
    }
}

function seed(\PDO $pdo): void
{
    $p = TEST_DB_PREFIX;

    $stmt = $pdo->prepare("INSERT IGNORE INTO `{$p}permissions` (slug, label, description) VALUES (?, ?, ?)");
    foreach (Auth::PERMISSIONS as $slug => [$label, $description]) {
        $stmt->execute([$slug, $label, $description]);
    }

    $roleStmt = $pdo->prepare(
        "INSERT INTO `{$p}roles` (slug, label, is_default) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE label = VALUES(label), is_default = 1"
    );
    foreach (Auth::DEFAULT_ROLE_PERMISSIONS as $role => $rolePermissions) {
        $roleStmt->execute([$role, ucfirst($role)]);
        foreach ($rolePermissions as $permission) {
            $pdo->prepare(
                "INSERT IGNORE INTO `{$p}role_permissions` (role_id, permission_id)
                 SELECT r.id, pe.id FROM `{$p}roles` r, `{$p}permissions` pe
                  WHERE r.slug = ? AND pe.slug = ?"
            )->execute([$role, $permission]);
        }
    }

    $stmt = $pdo->prepare(
        "INSERT INTO `{$p}settings` (`key`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
    );
    $stmt->execute(['site_name', 'ESSE Test']);
    $stmt->execute(['site_url', TEST_BASE_URL]);

    $userStmt = $pdo->prepare(
        "INSERT INTO `{$p}users` (display_name, email, password, role, email_verified_at) VALUES (?, ?, ?, ?, NOW())"
    );
    $userStmt->execute(['Forge Test', TEST_FORGE_EMAIL, password_hash(TEST_FORGE_PASSWORD, PASSWORD_BCRYPT), 'forge']);
    $userStmt->execute(['Member Test', TEST_MEMBER_EMAIL, password_hash(TEST_MEMBER_PASSWORD, PASSWORD_BCRYPT), 'member']);
}

function writeTestConfig(): void
{
    $dir = TEST_CONFIG_DIR . '/config';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        throw new \RuntimeException("Konnte {$dir} nicht anlegen.");
    }

    $config = "<?php\n\n"
        . "define('ESSE_DB_HOST', " . var_export(TEST_DB_HOST, true) . ");\n"
        . "define('ESSE_DB_PORT', " . var_export(TEST_DB_PORT, true) . ");\n"
        . "define('ESSE_DB_NAME', " . var_export(TEST_DB_NAME, true) . ");\n"
        . "define('ESSE_DB_USER', " . var_export(TEST_DB_USER, true) . ");\n"
        . "define('ESSE_DB_PASS', " . var_export(TEST_DB_PASS, true) . ");\n"
        . "define('ESSE_DB_PREFIX', " . var_export(TEST_DB_PREFIX, true) . ");\n"
        . "define('ESSE_URL', " . var_export(TEST_BASE_URL, true) . ");\n"
        . "define('ESSE_ENCRYPT_KEY', " . var_export(bin2hex(random_bytes(32)), true) . ");\n";

    file_put_contents($dir . '/config.php', $config);
}

// Direkter Aufruf: php tests/integration/bootstrap.php
if (realpath($argv[0] ?? '') === __FILE__) {
    $pdo = testPdo();
    resetDatabase($pdo);
    seed($pdo);
    writeTestConfig();
    echo "esse_test zurueckgesetzt und geseedet, Config: " . TEST_CONFIG_DIR . "/config/config.php\n";
}
