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
    ];

    // -- Update check --

    public static function checkForUpdate(): ?array
    {
        // /releases returns all releases incl. pre-releases; take the most recent
        $url = 'https://api.github.com/repos/' . \ESSE_GITHUB_REPO . '/releases';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => 8,
            \CURLOPT_USERAGENT      => 'ESSE-CMS/' . \ESSE_VERSION,
            \CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $json = curl_exec($ch);
        $code = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$json || $code !== 200) return null;

        $releases = json_decode($json, true);
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

    public static function createBackup(callable $log): string
    {
        $backupDir = \ESSE_PRIVATE_PATH . '/storage/backups';
        if (!is_dir($backupDir)) mkdir($backupDir, 0750, true);

        $stamp = date('Y-m-d_H-i-s');
        $zip   = new \ZipArchive();
        $dest  = $backupDir . '/pre-update_' . $stamp . '.zip';

        if ($zip->open($dest, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException("Backup: ZIP konnte nicht erstellt werden: {$dest}");
        }

        // DB dump
        $log('Datenbank sichern...');
        $sql = self::dbDump();
        $zip->addFromString('database.sql', $sql);

        // Files (skip storage and vendor to keep backup small)
        $log('Dateien sichern...');
        $root   = \ESSE_ROOT;
        $skip   = ['/storage/', '/public/vendor/'];
        $iter   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iter as $file) {
            if (!$file->isFile()) continue;
            $rel = str_replace($root . '/', '', $file->getPathname());
            foreach ($skip as $s) {
                if (str_contains($rel, ltrim($s, '/'))) continue 2;
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
