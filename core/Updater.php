<?php

declare(strict_types=1);

namespace Esse;

class Updater
{
    // Paths that must never be overwritten during an update
    private const PROTECTED = [
        'config/',
        'local.php',
        'storage/',
        'install/installed.lock',
        // Doku-Dateien: bleiben für Neuinstallationen im Release-ZIP, werden aber auf
        // bestehenden Instanzen nicht bei jedem Update neu auf den Live-Server kopiert
        'README.md',
        'CHANGELOG.md',
        'PLUGIN_GUIDE.md',
        'THEME_GUIDE.md',
    ];

    // -- Update check --

    public static function checkForUpdate(bool $includePrerelease = false): ?array
    {
        // /releases returns all releases; GitHubApi applies the optional token.
        $releases = GitHubApi::releases(\ESSE_GITHUB_REPO);
        if (!$releases) return null;

        // Filter out pre-releases if not opted in
        if (!$includePrerelease) {
            $releases = array_values(array_filter($releases, fn($r) => empty($r['prerelease'])));
        }

        if (empty($releases[0]['tag_name'])) return null;

        $data = $releases[0];

        return [
            'version'      => ltrim($data['tag_name'], 'v'),
            'tag'          => $data['tag_name'],
            'changelog'    => $data['body'] ?? '',
            'download_url' => $data['zipball_url'] ?? '',
            'published_at' => $data['published_at'] ?? '',
            'html_url'     => $data['html_url'] ?? '',
            'prerelease'   => $data['prerelease'] ?? false,
        ];
    }

    public static function isNewer(string $remote, string $current): bool
    {
        $remote  = ltrim($remote, 'v');
        $current = ltrim(preg_replace('/-dev$/', '', $current), 'v');
        return version_compare($remote, $current, '>');
    }

    // -- Backup --

    public static function createBackup(callable $log, string $prefix = 'backup'): string
    {
        $backupDir = \ESSE_PRIVATE_PATH . '/storage/backups';
        if (!is_dir($backupDir)) mkdir($backupDir, 0750, true);

        $stamp   = date('Y-m-d_H-i-s');
        $version = preg_replace('/[^a-zA-Z0-9.\-]/', '', \ESSE_VERSION);
        $zip     = new \ZipArchive();
        $prefix  = $prefix ?? 'backup';
        $dest    = $backupDir . '/' . $prefix . '_v' . $version . '_' . $stamp . '.zip';

        if ($zip->open($dest, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException("Backup: ZIP konnte nicht erstellt werden: {$dest}");
        }

        // DB dump
        $log('Datenbank sichern...');
        $sql = self::dbDump();
        $zip->addFromString('database.sql', $sql);

        // Files (skip storage and vendor to keep backup small)
        $log('Dateien sichern...');
        $root = \ESSE_ROOT;
        $skip = ['storage/', 'public/vendor/'];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iter as $file) {
            try {
                if (!$file->isFile()) continue;
            } catch (\Throwable) {
                continue; // open_basedir may block parent dir access
            }
            $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
            foreach ($skip as $s) {
                if (str_starts_with($rel, $s)) continue 2;
            }
            $zip->addFile($file->getPathname(), 'files/' . $rel);
        }

        $zip->close();
        $log('Backup gespeichert: ' . basename($dest));
        return $dest;
    }

    // -- Download --

    public static function download(string $url, callable $log): string
    {
        $tmp = \ESSE_PRIVATE_PATH . '/storage/update_tmp';
        if (!is_dir($tmp)) mkdir($tmp, 0750, true);

        $dest = $tmp . '/update.zip';
        $log('Herunterladen von GitHub...');

        $ctx = stream_context_create(['http' => [
            'header'          => "User-Agent: ESSE-CMS/" . \ESSE_VERSION . "\r\n",
            'timeout'         => 60,
            'follow_location' => true,
        ]]);

        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            throw new \RuntimeException('Download fehlgeschlagen. Bitte Internetverbindung prüfen.');
        }

        file_put_contents($dest, $data);
        $log('Download abgeschlossen (' . round(strlen($data) / 1024) . ' KB)');
        return $dest;
    }

    // -- Apply --

    public static function apply(string $zipPath, callable $log): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Update-ZIP konnte nicht geöffnet werden.');
        }

        $log('Entpacke Update...');
        $root  = \ESSE_ROOT;
        $count = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Strip leading directory (GitHub ZIPs have a root folder like nfsmw15-esse-cms-abc123/)
            $rel = preg_replace('#^[^/]+/#', '', $name);
            if ($rel === '' || str_ends_with($rel, '/')) continue;

            // Security: no path traversal
            if (str_contains($rel, '..')) continue;

            // Skip protected paths
            if (self::isProtected($rel)) continue;

            $target = $root . '/' . $rel;
            $dir    = dirname($target);
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            file_put_contents($target, $zip->getFromIndex($i));
            $count++;
        }

        $zip->close();
        $log("{$count} Dateien aktualisiert.");

        // Cleanup temp
        @unlink($zipPath);
        @rmdir(dirname($zipPath));
    }

    // -- DB dump (pure PHP, no mysqldump needed) --

    public static function dbDump(): string
    {
        $pdo    = DB::connection();
        $prefix = defined('ESSE_DB_PREFIX') ? \ESSE_DB_PREFIX : 'esse_';
        $sql    = "-- ESSE CMS DB Backup " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\n\n";

        $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            if (!str_starts_with($table, $prefix)) continue;

            $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_NUM);
            $sql   .= "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n";

            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
                $sql .= "INSERT INTO `{$table}` VALUES (" . implode(',', $vals) . ");\n";
            }
            $sql .= "\n";
        }

        return $sql;
    }

    // -- Restore --

    public static function restore(string $zipPath, callable $log): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Backup-ZIP konnte nicht geöffnet werden.');
        }

        // 1. Restore database
        $log('Datenbank wiederherstellen...');
        $sqlContent = $zip->getFromName('database.sql');
        if ($sqlContent !== false) {
            self::dbImport($sqlContent);
            $log('Datenbank wiederhergestellt.');
        } else {
            $log('Kein database.sql im Backup gefunden — übersprungen.');
        }

        // 2. Restore files (skip protected paths)
        $log('Dateien wiederherstellen...');
        $root  = \ESSE_ROOT;
        $count = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!str_starts_with($name, 'files/')) continue;

            $rel = substr($name, 6); // strip 'files/'
            if ($rel === '' || str_ends_with($rel, '/') || str_contains($rel, '..')) continue;
            if (self::isProtected($rel)) continue;

            $dest = $root . '/' . $rel;
            if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
            file_put_contents($dest, $zip->getFromIndex($i));
            $count++;
        }

        $zip->close();
        $log("{$count} Dateien wiederhergestellt.");
    }

    private static function dbImport(string $sql): void
    {
        // Statement-für-Statement per PDO (mit oder ohne umschließende Transaktion) war für
        // Dumps mit vielen Datenzeilen (z.B. Plugin-Statistiken, >80.000 Einzeil-INSERTs in der
        // Praxis) unbrauchbar langsam — mehrere Minuten statt Sekunden, weit über jedem
        // Web-Request-Timeout. Eine Transaktion allein half nicht: das Dump-Format enthält pro
        // Tabelle DROP/CREATE TABLE, und DDL committed in MySQL immer implizit, bricht also jede
        // PHP-seitige Transaktion mitten im Import. Statt SQL-Statements in PHP nachzubauen, der
        // Import läuft über den mysql-CLI-Client — exakt dafür gebaut, behandelt Transaktions-
        // grenzen/Multi-Statements/Packet-Größen korrekt und ist um Größenordnungen schneller.
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('Datenbank-Restore benötigt proc_open() (vom Hoster deaktiviert).');
        }

        $tmpSql = ESSE_PRIVATE_PATH . '/storage/update_tmp/restore_' . uniqid() . '.sql';
        $tmpCnf = ESSE_PRIVATE_PATH . '/storage/update_tmp/restore_' . uniqid() . '.cnf';
        $dir    = dirname($tmpSql);
        if (!is_dir($dir)) mkdir($dir, 0750, true);

        // Zugangsdaten über eine Optionsdatei statt als CLI-Argument — sonst stünde das
        // DB-Passwort im Klartext in der Prozessliste (`ps aux`), sichtbar für andere Nutzer auf
        // dem Server.
        // Werte in Anführungszeichen: ein Passwort mit "#" würde sonst als Kommentar geparst und
        // an dieser Stelle abgeschnitten werden (MySQL-Optionsdateien kennen "#" als Kommentarzeichen).
        file_put_contents($tmpCnf, sprintf(
            "[client]\nhost=\"%s\"\nport=%d\nuser=\"%s\"\npassword=\"%s\"\n",
            defined('ESSE_DB_HOST') ? \ESSE_DB_HOST : 'localhost',
            defined('ESSE_DB_PORT') ? \ESSE_DB_PORT : 3306,
            str_replace('"', '\"', \ESSE_DB_USER),
            str_replace('"', '\"', \ESSE_DB_PASS)
        ));
        chmod($tmpCnf, 0600);
        // autocommit=0: INSERTs zwischen zwei DDL-Anweisungen (DROP/CREATE TABLE) sammeln sich
        // dann zu einer Transaktion statt pro Zeile zu committen (fsync) — DDL committed in
        // MySQL ohnehin immer implizit, das ist also automatisch "eine Transaktion pro Tabelle"
        // ohne dass die Statement-Grenzen dafür im Dump erkannt werden müssten.
        file_put_contents(
            $tmpSql,
            "SET FOREIGN_KEY_CHECKS=0;\nSET autocommit=0;\n" . $sql . "\nCOMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n"
        );

        $cmd = sprintf(
            '%s --defaults-extra-file=%s %s < %s 2>&1',
            escapeshellarg(self::mysqlBinary()),
            escapeshellarg($tmpCnf),
            escapeshellarg(\ESSE_DB_NAME),
            escapeshellarg($tmpSql)
        );
        exec($cmd, $output, $exitCode);

        @unlink($tmpCnf);
        @unlink($tmpSql);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Datenbank-Import fehlgeschlagen: ' . implode("\n", $output));
        }
    }

    private static function mysqlBinary(): string
    {
        foreach (['mariadb', 'mysql'] as $bin) {
            exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $code);
            if ($code === 0 && !empty($out[0])) return $out[0];
        }
        throw new \RuntimeException('Kein mysql/mariadb-CLI-Client auf dem Server gefunden.');
    }

    private static function isProtected(string $rel): bool
    {
        foreach (self::PROTECTED as $protected) {
            if (str_starts_with($rel, $protected) || $rel === rtrim($protected, '/')) {
                return true;
            }
        }
        return false;
    }
}
