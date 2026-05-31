<?php

declare(strict_types=1);

// Already installed?
if (file_exists(__DIR__ . '/installed.lock')) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>ESSE CMS</title>'
        . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3/dist/css/bootstrap.min.css"></head>'
        . '<body class="d-flex align-items-center justify-content-center vh-100 bg-dark text-white">'
        . '<div class="text-center"><h1 class="display-4">Already installed.</h1>'
        . '<p class="text-secondary">Delete <code>install/installed.lock</code> to reinstall.</p></div></body></html>';
    exit;
}

$step   = max(1, min(4, (int) ($_GET['step'] ?? 1)));
$errors = [];

// --- POST processing ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case 1:
            if (allChecksPass()) {
                header('Location: /install?step=2');
                exit;
            }
            $errors[] = 'Bitte zuerst alle Systemvoraussetzungen erfüllen.';
            break;

        case 2:
            $host   = trim($_POST['db_host']   ?? 'localhost');
            $port   = (int) ($_POST['db_port']  ?? 3306);
            $name   = trim($_POST['db_name']   ?? '');
            $user   = trim($_POST['db_user']   ?? '');
            $pass   = $_POST['db_pass']        ?? '';
            $prefix = preg_replace('/[^a-z0-9_]/i', '', trim($_POST['db_prefix'] ?? 'esse_'));

            if (!$name || !$user) {
                $errors[] = 'Datenbankname und Benutzer sind Pflichtfelder.';
                break;
            }
            try {
                $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
                new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $_SESSION['esse_install']['db'] = compact('host', 'port', 'name', 'user', 'pass', 'prefix');
                header('Location: /install?step=3');
                exit;
            } catch (\PDOException $e) {
                $errors[] = 'Verbindung fehlgeschlagen: ' . htmlspecialchars($e->getMessage());
            }
            break;

        case 3:
            $siteName    = trim($_POST['site_name']    ?? '');
            $siteUrl     = rtrim(trim($_POST['site_url'] ?? ''), '/');
            $privatePath = rtrim(trim($_POST['private_path'] ?? ''), '/');

            if (!$siteName || !$siteUrl) {
                $errors[] = 'Seitenname und URL sind Pflichtfelder.';
            } elseif (!filter_var($siteUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'Bitte eine gültige URL eingeben (z.B. https://example.com).';
            } elseif ($privatePath && !is_dir($privatePath)) {
                $errors[] = "Privater Pfad existiert nicht: {$privatePath}";
            } elseif ($privatePath && !is_writable($privatePath)) {
                $errors[] = "Privater Pfad ist nicht schreibbar: {$privatePath}";
            } else {
                $_SESSION['esse_install']['site'] = compact('siteName', 'siteUrl', 'privatePath');
                header('Location: /install?step=4');
                exit;
            }
            break;

        case 4:
            $username        = trim($_POST['username']         ?? '');
            $email           = trim($_POST['email']            ?? '');
            $password        = $_POST['password']              ?? '';
            $passwordConfirm = $_POST['password_confirm']      ?? '';

            if (!$username || !$email || !$password) {
                $errors[] = 'Alle Felder sind Pflichtfelder.';
            } elseif (!preg_match('/^[a-zA-Z0-9_.\-]{3,50}$/', $username)) {
                $errors[] = 'Benutzername: 3–50 Zeichen, nur Buchstaben, Ziffern, _, ., -';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Ungültige E-Mail-Adresse.';
            } elseif (strlen($password) < 10) {
                $errors[] = 'Passwort muss mindestens 10 Zeichen lang sein.';
            } elseif ($password !== $passwordConfirm) {
                $errors[] = 'Passwörter stimmen nicht überein.';
            } else {
                try {
                    runSetup($_SESSION['esse_install'], $username, $email, $password);
                    unset($_SESSION['esse_install']);
                    header('Location: /admin');
                    exit;
                } catch (\Throwable $e) {
                    $errors[] = 'Setup fehlgeschlagen: ' . htmlspecialchars($e->getMessage());
                }
            }
            break;
    }
}

// --- Setup ---

function runSetup(array $data, string $username, string $email, string $password): void
{
    $db          = $data['db'];
    $site        = $data['site'];
    $p           = $db['prefix'];
    $privatePath = $site['privatePath'] ?: ESSE_ROOT;

    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
    $pdo = new \PDO($dsn, $db['user'], $db['pass'], [
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);

    // Schema
    foreach (schema($p) as $sql) {
        $pdo->exec($sql);
    }

    // Default permissions
    $permissions = [
        ['php_upload',      'PHP/HTML hochladen',       'Eigene PHP- und HTML-Dateien als Seiten hochladen'],
        ['manage_users',    'Benutzer verwalten',        'Benutzer anlegen, bearbeiten und deaktivieren'],
        ['manage_admins',   'Admins verwalten',          'Benutzer zur Admin-Rolle befördern'],
        ['manage_plugins',  'Plugins verwalten',         'Plugins installieren, aktualisieren und entfernen'],
        ['manage_themes',   'Themes verwalten',          'Themes installieren und wechseln'],
        ['manage_repos',    'Repos verwalten',           'Plugin- und Theme-Repos hinzufügen und entfernen'],
        ['manage_settings', 'Einstellungen verwalten',   'Systemeinstellungen ändern'],
        ['manage_content',  'Inhalte verwalten',         'Seiten und Inhalte erstellen, bearbeiten, löschen'],
        ['manage_files',    'Dateien verwalten',         'Dateien hochladen und verwalten'],
        ['view_logs',       'Logs einsehen',             'System- und Zugriffslogs anzeigen'],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO `{$p}permissions` (slug, label, description) VALUES (?, ?, ?)");
    foreach ($permissions as $perm) {
        $stmt->execute($perm);
    }

    // Default settings
    $stmt = $pdo->prepare("INSERT INTO `{$p}settings` (`key`, `value`) VALUES (?, ?)");
    $stmt->execute(['site_name', $site['siteName']]);
    $stmt->execute(['site_url',  $site['siteUrl']]);

    // Forge account
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO `{$p}users` (username, email, password, role) VALUES (?, ?, ?, 'forge')")
        ->execute([$username, $email, $hash]);

    // Write config.php
    $configDir = $privatePath . '/config';
    if (!is_dir($configDir)) {
        mkdir($configDir, 0750, true);
    }
    $config = "<?php\n\n"
        . "define('ESSE_DB_HOST',   " . var_export($db['host'], true)  . ");\n"
        . "define('ESSE_DB_PORT',   " . var_export($db['port'], true)  . ");\n"
        . "define('ESSE_DB_NAME',   " . var_export($db['name'], true)  . ");\n"
        . "define('ESSE_DB_USER',   " . var_export($db['user'], true)  . ");\n"
        . "define('ESSE_DB_PASS',   " . var_export($db['pass'], true)  . ");\n"
        . "define('ESSE_DB_PREFIX', " . var_export($p, true)           . ");\n"
        . "define('ESSE_URL',       " . var_export($site['siteUrl'], true) . ");\n";

    file_put_contents($configDir . '/config.php', $config);
    chmod($configDir . '/config.php', 0640);

    // Write local.php if private path differs from webroot
    if ($site['privatePath'] && $site['privatePath'] !== ESSE_ROOT) {
        $local = "<?php\ndefine('ESSE_PRIVATE_PATH', " . var_export($site['privatePath'], true) . ");\n";
        file_put_contents(ESSE_ROOT . '/local.php', $local);
        chmod(ESSE_ROOT . '/local.php', 0640);
    }

    // Lock installer
    file_put_contents(__DIR__ . '/installed.lock', 'Installed: ' . date('c') . "\n");
}

function schema(string $p): array
{
    return [
        "CREATE TABLE IF NOT EXISTS `{$p}users` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `username`   VARCHAR(50)  NOT NULL,
            `email`      VARCHAR(255) NOT NULL,
            `password`   VARCHAR(255) NOT NULL,
            `role`       ENUM('forge','admin','editor','author','member') NOT NULL DEFAULT 'member',
            `active`     TINYINT(1)   NOT NULL DEFAULT 1,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_username` (`username`),
            UNIQUE KEY `uq_email`    (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$p}permissions` (
            `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `slug`        VARCHAR(100) NOT NULL,
            `label`       VARCHAR(255) NOT NULL,
            `description` TEXT,
            UNIQUE KEY `uq_slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$p}roles` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `slug`       VARCHAR(50)  NOT NULL,
            `label`      VARCHAR(255) NOT NULL,
            `is_default` TINYINT(1)   NOT NULL DEFAULT 0,
            UNIQUE KEY `uq_slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$p}role_permissions` (
            `role_id`       INT UNSIGNED NOT NULL,
            `permission_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`role_id`, `permission_id`),
            FOREIGN KEY (`role_id`)       REFERENCES `{$p}roles`(`id`)       ON DELETE CASCADE,
            FOREIGN KEY (`permission_id`) REFERENCES `{$p}permissions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$p}user_permissions` (
            `user_id`         INT UNSIGNED NOT NULL,
            `permission_slug` VARCHAR(100) NOT NULL,
            `granted`         TINYINT(1)   NOT NULL DEFAULT 1,
            PRIMARY KEY (`user_id`, `permission_slug`),
            FOREIGN KEY (`user_id`) REFERENCES `{$p}users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$p}pages` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `slug`       VARCHAR(255) NOT NULL,
            `title`      VARCHAR(500) NOT NULL,
            `content`    LONGTEXT,
            `type`       ENUM('standard','php') NOT NULL DEFAULT 'standard',
            `file_path`  VARCHAR(500) DEFAULT NULL,
            `visibility` ENUM('public','members','admin') NOT NULL DEFAULT 'public',
            `status`     ENUM('published','draft')        NOT NULL DEFAULT 'draft',
            `author_id`  INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_slug` (`slug`),
            FOREIGN KEY (`author_id`) REFERENCES `{$p}users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$p}settings` (
            `key`   VARCHAR(100) NOT NULL PRIMARY KEY,
            `value` TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

// --- System checks ---

function systemCheck(): array
{
    return [
        ['PHP 8.1+',             PHP_VERSION_ID >= 80100,                 PHP_VERSION],
        ['PDO MySQL',            extension_loaded('pdo_mysql'),           ''],
        ['config/ schreibbar',   is_writable(ESSE_ROOT . '/config'),      ''],
        ['storage/ schreibbar',  is_writable(ESSE_ROOT . '/storage'),     ''],
        ['install/ schreibbar',  is_writable(ESSE_ROOT . '/install'),     ''],
    ];
}

function allChecksPass(): bool
{
    foreach (systemCheck() as [, $ok]) {
        if (!$ok) return false;
    }
    return true;
}

// --- HTML ---

$prev = $data['db']   ?? [];
$prevSite = $data['site'] ?? [];
$stepLabels = ['Systemprüfung', 'Datenbank', 'Website', 'Forge-Account'];

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ESSE CMS — Installation</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3/dist/css/bootstrap.min.css">
    <style>
        body { background: #0d0d0d; color: #e0e0e0; }
        .card { background: #1a1a1a; border: 1px solid #2d2d2d; }
        .card-header { background: #111; border-bottom: 1px solid #2d2d2d; }
        .form-control, .form-select { background: #111; border-color: #333; color: #e0e0e0; }
        .form-control:focus, .form-select:focus { background: #111; border-color: #666; color: #e0e0e0; box-shadow: none; }
        .form-text { color: #666; }
        .step-badge { width: 2rem; height: 2rem; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 600; }
        .step-done  { background: #198754; color: #fff; }
        .step-active { background: #0d6efd; color: #fff; }
        .step-pending { background: #2d2d2d; color: #666; }
        .brand { font-size: 1.5rem; font-weight: 700; letter-spacing: .1em; }
    </style>
</head>
<body>
<div class="container py-5" style="max-width: 640px">

    <!-- Brand -->
    <div class="text-center mb-4">
        <div class="brand text-white">ESSE CMS</div>
        <small class="text-secondary">forge your web.</small>
    </div>

    <!-- Step indicator -->
    <div class="d-flex align-items-center justify-content-center gap-3 mb-4">
        <?php foreach ($stepLabels as $i => $label):
            $n = $i + 1;
            $cls = $n < $step ? 'step-done' : ($n === $step ? 'step-active' : 'step-pending');
        ?>
        <div class="text-center">
            <div class="step-badge <?= $cls ?> mx-auto">
                <?= $n < $step ? '✓' : $n ?>
            </div>
            <small class="d-block mt-1 <?= $n === $step ? 'text-white' : 'text-secondary' ?>" style="font-size:.75rem">
                <?= htmlspecialchars($label) ?>
            </small>
        </div>
        <?php if ($i < count($stepLabels) - 1): ?>
            <div class="flex-grow-1" style="height:1px; background:#2d2d2d; margin-bottom:1.5rem"></div>
        <?php endif ?>
        <?php endforeach ?>
    </div>

    <!-- Errors -->
    <?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach ?>
    </div>
    <?php endif ?>

    <div class="card">
        <div class="card-header py-3">
            <strong><?= htmlspecialchars($stepLabels[$step - 1]) ?></strong>
        </div>
        <div class="card-body p-4">

        <?php if ($step === 1): ?>
        <!-- Step 1: System check -->
        <p class="text-secondary mb-4">Systemvoraussetzungen werden geprüft.</p>
        <table class="table table-dark table-sm mb-4">
            <tbody>
            <?php foreach (systemCheck() as [$label, $ok, $detail]): ?>
                <tr>
                    <td><?= htmlspecialchars($label) ?></td>
                    <td class="text-end">
                        <?php if ($ok): ?>
                            <span class="text-success">✓ <?= htmlspecialchars($detail) ?></span>
                        <?php else: ?>
                            <span class="text-danger">✗ Fehlt</span>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        <form method="post" action="/install?step=1">
            <button class="btn btn-primary w-100" <?= allChecksPass() ? '' : 'disabled' ?>>
                Weiter
            </button>
        </form>

        <?php elseif ($step === 2): ?>
        <!-- Step 2: Database -->
        <form method="post" action="/install?step=2">
            <div class="row g-3">
                <div class="col-8">
                    <label class="form-label">Host</label>
                    <input type="text" name="db_host" class="form-control" value="<?= htmlspecialchars($prev['host'] ?? 'localhost') ?>" required>
                </div>
                <div class="col-4">
                    <label class="form-label">Port</label>
                    <input type="number" name="db_port" class="form-control" value="<?= htmlspecialchars((string)($prev['port'] ?? 3306)) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Datenbankname</label>
                    <input type="text" name="db_name" class="form-control" value="<?= htmlspecialchars($prev['name'] ?? '') ?>" required>
                </div>
                <div class="col-6">
                    <label class="form-label">Benutzer</label>
                    <input type="text" name="db_user" class="form-control" value="<?= htmlspecialchars($prev['user'] ?? '') ?>" required>
                </div>
                <div class="col-6">
                    <label class="form-label">Passwort</label>
                    <input type="password" name="db_pass" class="form-control" autocomplete="new-password">
                </div>
                <div class="col-12">
                    <label class="form-label">Tabellen-Prefix</label>
                    <input type="text" name="db_prefix" class="form-control" value="<?= htmlspecialchars($prev['prefix'] ?? 'esse_') ?>">
                    <div class="form-text">Nur Buchstaben, Ziffern und _</div>
                </div>
            </div>
            <button class="btn btn-primary w-100 mt-4">Verbindung testen &amp; weiter</button>
        </form>

        <?php elseif ($step === 3): ?>
        <!-- Step 3: Site settings -->
        <form method="post" action="/install?step=3">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Seitenname</label>
                    <input type="text" name="site_name" class="form-control" value="<?= htmlspecialchars($prevSite['siteName'] ?? '') ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label">URL</label>
                    <input type="url" name="site_url" class="form-control" placeholder="https://example.com" value="<?= htmlspecialchars($prevSite['siteUrl'] ?? '') ?>" required>
                </div>
                <div class="col-12 mt-2">
                    <label class="form-label">Privater Pfad <span class="text-secondary">(optional)</span></label>
                    <input type="text" name="private_path" class="form-control font-monospace" placeholder="/home/user/private/esse" value="<?= htmlspecialchars($prevSite['privatePath'] ?? '') ?>">
                    <div class="form-text">
                        Absoluter Pfad außerhalb des Webroots für config/ und storage/.<br>
                        Empfohlen auf VPS/HestiaCP — verhindert HTTP-Zugriff auf Konfigurationsdateien auch bei fehlerhafter Server-Konfiguration.
                        Leer lassen für Standard-Setup.
                    </div>
                </div>
            </div>
            <button class="btn btn-primary w-100 mt-4">Weiter</button>
        </form>

        <?php elseif ($step === 4): ?>
        <!-- Step 4: Forge account -->
        <p class="text-secondary mb-4">
            Der Forge-Account ist der Betreiber-Account. Er kann nicht von anderen Benutzern entfernt oder degradiert werden.
        </p>
        <form method="post" action="/install?step=4">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Benutzername</label>
                    <input type="text" name="username" class="form-control" autocomplete="username" required>
                    <div class="form-text">3–50 Zeichen, nur a–z, 0–9, _, ., -</div>
                </div>
                <div class="col-12">
                    <label class="form-label">E-Mail</label>
                    <input type="email" name="email" class="form-control" autocomplete="email" required>
                </div>
                <div class="col-6">
                    <label class="form-label">Passwort</label>
                    <input type="password" name="password" class="form-control" autocomplete="new-password" required>
                    <div class="form-text">Mindestens 10 Zeichen</div>
                </div>
                <div class="col-6">
                    <label class="form-label">Passwort bestätigen</label>
                    <input type="password" name="password_confirm" class="form-control" autocomplete="new-password" required>
                </div>
            </div>
            <button class="btn btn-success w-100 mt-4">Installation abschließen</button>
        </form>
        <?php endif ?>

        </div>
    </div>

    <p class="text-center text-secondary mt-4" style="font-size:.8rem">
        ESSE CMS <?= ESSE_VERSION ?> &nbsp;·&nbsp; AGPL-3.0
    </p>
</div>
</body>
</html>
