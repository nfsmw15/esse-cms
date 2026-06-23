<?php

declare(strict_types=1);

namespace Esse;

class Updater
{
    // Paths that must never be overwritten during an update. storage/uploads/ bewusst NICHT
    // hier (anders als frueher als ganzes "storage/" geblockt) — dort liegen jetzt private
    // Mediendateien (siehe Esse\Media), die wie public/uploads/ ganz normal Teil von Backup/
    // Restore sein muessen, sonst wuerden sie nie gesichert oder wiederhergestellt.
    private const PROTECTED = [
        'config/',
        'local.php',
        'storage/backups/',
        'storage/cache/',
        'storage/update_tmp/',
        'install/installed.lock',
        // Doku-Dateien: bleiben für Neuinstallationen im Release-ZIP, werden aber auf
        // bestehenden Instanzen nicht bei jedem Update neu auf den Live-Server kopiert
        'README.md',
        'CHANGELOG.md',
        'PLUGIN_GUIDE.md',
        'THEME_GUIDE.md',
    ];

    // Pfade, die ein Backup grundsätzlich nie erfasst — entweder weil sie sonst eine Backup-in-
    // Backup-Rekursion erzeugen wuerden (storage/backups/), reine Laufzeit-Artefakte sind
    // (storage/cache/, storage/update_tmp/) oder zu groß/nicht sinnvoll snapshotbar sind
    // (public/vendor/). Beim "vollständigen" Restore (Aufräumen verwaister Dateien, die nach dem
    // Backup neu hinzugekommen sind) dürfen genau diese Pfade nicht angetastet werden — sonst
    // würden z.B. alle Icon-Packs/Vendor-Assets gelöscht, nur weil sie nie im Backup enthalten waren.
    private const BACKUP_EXCLUDED = [
        'storage/backups/',
        'storage/cache/',
        'storage/update_tmp/',
        'public/vendor/',
    ];

    // Grenzwerte gegen Zip-Bomben/überdimensionierte ZIPs — gelten fuer Update-ZIPs genauso wie
    // fuer Plugin-/Theme-/Iconpack-Pakete (siehe checkZipLimits() unten, von beiden genutzt).
    public const MAX_ZIP_BYTES   = 20 * 1024 * 1024;  // 20 MB komprimiertes ZIP
    public const MAX_FILES       = 1000;              // Anzahl Eintraege im ZIP
    public const MAX_FILE_BYTES  = 20 * 1024 * 1024;  // 20 MB pro Einzeldatei (entpackt)
    public const MAX_TOTAL_BYTES = 80 * 1024 * 1024;  // 80 MB gesamt (entpackt)

    // Eigene, grosszuegigere Grenzwerte fuer Backup-ZIPs: die enthalten einen vollen DB-Dump
    // (in der Praxis schon 80.000+ INSERT-Zeilen in einer einzigen Tabelle) plus alle Uploads —
    // die PKG-Limits oben waeren dafuer zu eng und wuerden echte Backups beim Restore ablehnen.
    public const BACKUP_MAX_ZIP_BYTES   = 500 * 1024 * 1024;        // 500 MB komprimiertes ZIP
    public const BACKUP_MAX_FILES       = 50000;                    // Anzahl Eintraege im ZIP
    public const BACKUP_MAX_FILE_BYTES  = 200 * 1024 * 1024;        // 200 MB pro Einzeldatei (entpackt)
    public const BACKUP_MAX_TOTAL_BYTES = 2 * 1024 * 1024 * 1024;   // 2 GB gesamt (entpackt)

    // Prueft Groesse, Dateianzahl, Symlinks/Spezialdateien und optional eine Endungs-Allowlist
    // VOR dem Dekomprimieren (anhand der Central-Directory-Metadaten) — bei einem einzigen
    // verdaechtigen Eintrag wird das GANZE ZIP abgelehnt, nicht nur dieser Eintrag. Gemeinsam
    // genutzt von Updater::apply() (CMS-Selbst-Update), Updater::restore() (Backup-Wiederher-
    // stellung, mit den groesseren BACKUP_*-Grenzwerten) und packageInstallZip() (Plugins/
    // Themes/Icon-Packs) — eine Stelle statt mehreren, nachdem Update-ZIPs diese Pruefung lange
    // gar nicht hatten (siehe Lehre aus der Icon-Pack-RCE 0.8.7).
    public static function checkZipLimits(
        \ZipArchive $zip,
        string $tmpFile,
        ?array $allowedExtensions = null,
        int $maxZipBytes = self::MAX_ZIP_BYTES,
        int $maxFiles = self::MAX_FILES,
        int $maxFileBytes = self::MAX_FILE_BYTES,
        int $maxTotalBytes = self::MAX_TOTAL_BYTES
    ): ?string {
        if (filesize($tmpFile) > $maxZipBytes) {
            return 'ZIP-Datei zu groß (max. ' . (int) ($maxZipBytes / 1024 / 1024) . ' MB).';
        }
        if ($zip->numFiles > $maxFiles) {
            return 'ZIP enthält zu viele Dateien (max. ' . $maxFiles . ').';
        }

        $totalBytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) continue;
            $name  = $stat['name'];
            $isDir = str_ends_with($name, '/');

            // Symlinks/Spezialdateien (Block-/Char-Devices, FIFOs, Sockets) — erkannt anhand des
            // Unix-Modes in den External Attributes — lehnen das GESAMTE ZIP ab, kein stilles
            // Uebersrpingen einzelner Eintraege.
            if (!$isDir && $zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX) {
                $unixMode = ($attr >> 16) & 0xF000;
                if ($unixMode !== 0 && $unixMode !== 0x8000) { // 0x8000 = regulaere Datei, 0 = kein Unix-Mode gesetzt
                    return 'ZIP enthält einen Symlink oder eine Spezialdatei (' . basename($name) . ') — Paket wird abgelehnt.';
                }
            }

            if (!$isDir && $allowedExtensions !== null) {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExtensions, true)) {
                    return 'Dateityp nicht erlaubt im Paket: ' . basename($name) . ($ext !== '' ? " (.{$ext})" : '');
                }
            }

            if ($stat['size'] > $maxFileBytes) {
                return 'Einzeldatei im ZIP zu groß (max. ' . (int) ($maxFileBytes / 1024 / 1024) . ' MB): ' . basename($name);
            }
            $totalBytes += $stat['size'];
            if ($totalBytes > $maxTotalBytes) {
                return 'ZIP zu groß entpackt (max. ' . (int) ($maxTotalBytes / 1024 / 1024) . ' MB gesamt) — möglicher Zip-Bomb.';
            }
        }

        return null;
    }

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
            if (self::isBackupExcluded($rel)) continue;
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

        // Vorprüfung VOR jedem Schreiben — ein Update-ZIP kam bisher ungeprüft direkt zur
        // Extraktion (anders als Plugin-/Theme-/Icon-Pack-Uploads, die schon laenger durch
        // checkZipLimits() laufen). Ohne diese Pruefung schreibt ein manipuliertes Release-ZIP
        // (kompromittierter Update-Kanal, Symlink auf z.B. /etc/passwd, Zip-Bomb) unkontrolliert
        // ins Webroot. Forge-only, also kein akutes Public-RCE, aber unnoetiges Schadenspotenzial
        // bei einem kompromittierten Kanal — daher dieselbe Haertung wie bei Paket-Uploads.
        $limitError = self::checkZipLimits($zip, $zipPath);
        if ($limitError !== null) {
            $zip->close();
            throw new \RuntimeException("Update-ZIP abgelehnt: {$limitError}");
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

            // Defense-in-depth: tatsaechlich dekomprimierte Groesse erneut gegen das Limit
            // pruefen, falls Central-Directory- und Local-File-Header-Metadaten abweichen.
            $content = $zip->getFromIndex($i);
            if ($content === false || strlen($content) > self::MAX_FILE_BYTES) continue;

            $target = $root . '/' . $rel;
            $dir    = dirname($target);
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            file_put_contents($target, $content);
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

    // $fullClean: nach dem Zurückschreiben der Backup-Dateien zusätzlich Dateien entfernen, die
    // seit dem Backup neu im Webroot entstanden sind (z.B. Uploads) — sonst bleibt der DB-Eintrag
    // zwar weg, die Datei selbst aber weiterhin direkt per HTTP erreichbar. Pfade, die ein Backup
    // grundsätzlich nie erfasst (BACKUP_EXCLUDED, z.B. public/vendor/), werden davon nie berührt.
    public static function restore(string $zipPath, callable $log, bool $fullClean = true): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Backup-ZIP konnte nicht geöffnet werden.');
        }

        // Vorprüfung VOR DB-Import und VOR jedem Datei-Schreiben — Backups werden vom CMS selbst
        // erzeugt, sind also kein Public-Upload-Vektor, trotzdem soll ein manipuliertes/korruptes
        // Backup-ZIP (Symlink, Zip-Bomb) nicht unkontrolliert verarbeitet werden. Eigene, deutlich
        // grosszuegigere Grenzwerte als bei Plugin-/Update-ZIPs, weil ein echter Backup-ZIP einen
        // vollen DB-Dump (potenziell zehntausende Zeilen) plus alle Uploads enthaelt.
        $limitError = self::checkZipLimits(
            $zip, $zipPath, null,
            self::BACKUP_MAX_ZIP_BYTES, self::BACKUP_MAX_FILES, self::BACKUP_MAX_FILE_BYTES, self::BACKUP_MAX_TOTAL_BYTES
        );
        if ($limitError !== null) {
            $zip->close();
            throw new \RuntimeException("Backup-ZIP abgelehnt: {$limitError}");
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
        $root          = \ESSE_ROOT;
        $count         = 0;
        $backedUpPaths = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!str_starts_with($name, 'files/')) continue;

            $rel = substr($name, 6); // strip 'files/'
            if ($rel === '' || str_ends_with($rel, '/') || str_contains($rel, '..')) continue;
            if (self::isProtected($rel)) continue;

            $backedUpPaths[$rel] = true;

            // Defense-in-depth: tatsaechlich dekomprimierte Groesse erneut gegen das Limit
            // pruefen, falls Central-Directory- und Local-File-Header-Metadaten abweichen.
            $content = $zip->getFromIndex($i);
            if ($content === false || strlen($content) > self::BACKUP_MAX_FILE_BYTES) continue;

            $dest = $root . '/' . $rel;
            if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
            file_put_contents($dest, $content);
            $count++;
        }

        $zip->close();
        $log("{$count} Dateien wiederhergestellt.");

        // 3. Vollständiger Restore: Dateien entfernen, die nicht (mehr) im Backup stehen.
        if ($fullClean) {
            $removed = self::removeOrphanedFiles($root, $backedUpPaths);
            if ($removed > 0) {
                $log("{$removed} Datei(en) entfernt, die seit dem Backup neu hinzugekommen sind.");
            }
        }
    }

    private static function removeOrphanedFiles(string $root, array $backedUpPaths): int
    {
        $removed = 0;
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
            if (self::isProtected($rel) || self::isBackupExcluded($rel)) continue;
            if (isset($backedUpPaths[$rel])) continue;

            @unlink($file->getPathname());
            $removed++;
        }

        self::removeEmptyDirs($root);

        return $removed;
    }

    // Räumt Verzeichnisse auf, die durchs Entfernen verwaister Dateien leer geblieben sind.
    private static function removeEmptyDirs(string $root): void
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iter as $file) {
            try {
                if (!$file->isDir()) continue;
            } catch (\Throwable) {
                continue;
            }
            $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
            if (self::isProtected($rel) || self::isBackupExcluded($rel)) continue;
            @rmdir($file->getPathname()); // schlägt für nicht-leere Verzeichnisse einfach fehl
        }
    }

    private static function dbImport(string $sql): void
    {
        // Statement-für-Statement mit Autocommit war für Dumps mit vielen Datenzeilen (z.B.
        // Plugin-Statistiken, >80.000 Einzeil-INSERTs in der Praxis) unbrauchbar langsam —
        // mehrere Minuten statt Sekunden, weit über jedem Web-Request-Timeout. PDO::beginTransaction()
        // allein half nicht: das Dump-Format enthält pro Tabelle DROP/CREATE TABLE, und DDL
        // committed in MySQL immer implizit — das beendet eine über die PDO-API verwaltete
        // Transaktion vorzeitig (PDO weiß davon nichts und merkt sich weiter "Transaktion läuft").
        // Lösung: "SET autocommit=0" direkt per SQL statt über PDO::beginTransaction() — dann
        // sammeln sich INSERTs zwischen zwei DDL-Anweisungen automatisch zu einer Transaktion
        // (DDL committed ohnehin implizit), ganz ohne dass PDO eigenen Transaktionsstatus führen
        // oder die Statement-Grenzen im Dump dafür erkannt werden müssten. Ein eigener
        // mysql-CLI-Aufruf wäre die robustere Lösung gewesen, ist auf vielen Shared-Hosting-Setups
        // aber nicht nutzbar — exec()/proc_open() sind im Web-PHP-FPM-Pool oft deaktiviert (anders
        // als im CLI-PHP, das macht die Lücke beim Testen leicht unsichtbar).
        $pdo = DB::connection();
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $pdo->exec("SET autocommit=0");

        $statements = array_filter(
            array_map('trim', explode(";\n", $sql)),
            fn($s) => $s !== '' && !str_starts_with($s, '--')
        );

        foreach ($statements as $stmt) {
            if ($stmt === '') continue;
            try {
                $pdo->exec($stmt);
            } catch (\PDOException $e) {
                // Skip errors (e.g. duplicate tables) but continue
            }
        }

        // Letzte, noch offene Transaktion (Daten der letzten Tabelle im Dump, falls danach kein
        // weiteres DROP/CREATE TABLE mehr kam) explizit abschließen.
        try {
            $pdo->exec("COMMIT");
        } catch (\PDOException $e) {
            // Nichts mehr offen (letztes Statement war bereits DDL) — kein Fehler
        }
        $pdo->exec("SET autocommit=1");
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
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

    private static function isBackupExcluded(string $rel): bool
    {
        foreach (self::BACKUP_EXCLUDED as $excluded) {
            if (str_starts_with($rel, $excluded) || $rel === rtrim($excluded, '/')) {
                return true;
            }
        }
        return false;
    }
}
