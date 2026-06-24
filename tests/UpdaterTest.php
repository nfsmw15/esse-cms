<?php

declare(strict_types=1);

use Esse\Updater;

// Ruft die private removeOrphanedFiles() ueber Reflection mit einem Temp-Verzeichnis als Wurzel
// auf, statt \ESSE_ROOT zu beeinflussen (Konstante, kann im laufenden Prozess nicht neu gesetzt
// werden).
function callRemoveOrphanedFiles(string $root, array $backedUpPaths): int
{
    $ref    = new ReflectionClass(Updater::class);
    $method = $ref->getMethod('removeOrphanedFiles');
    return $method->invoke(null, $root, $backedUpPaths);
}

function makeFile(string $path): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, 'x');
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

// GitHub-Release-ZIPs haben immer einen Root-Ordner (z.B. nfsmw15-esse-cms-abc123/), den
// Updater::apply() abstreift — Fixtures bilden das nach.
function makeUpdateZipWithSymlink(): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-apply-symlink-') . '.zip';
    $zip = new \ZipArchive();
    $zip->open($zipPath, \ZipArchive::CREATE);
    $zip->addFromString('repo-abc123/__apply_test_marker__.txt', 'should not be written');
    $zip->addFromString('repo-abc123/evil-link', '/etc/passwd');
    $idx = $zip->locateName('repo-abc123/evil-link');
    $zip->setExternalAttributesIndex($idx, \ZipArchive::OPSYS_UNIX, 0120777 << 16); // S_IFLNK
    $zip->close();
    return $zipPath;
}

function makeUpdateZipWithOversizedFile(int $mb): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-apply-bigfile-') . '.zip';
    $zip = new \ZipArchive();
    $zip->open($zipPath, \ZipArchive::CREATE);
    $zip->addFromString('repo-abc123/__apply_test_marker__.txt', 'should not be written');
    $zip->addFromString('repo-abc123/big.bin', str_repeat("\0", $mb * 1024 * 1024));
    $zip->setCompressionName('repo-abc123/big.bin', \ZipArchive::CM_DEFLATE, 9);
    $zip->close();
    return $zipPath;
}

function makeUpdateZipWithManyFiles(int $fileCount): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-apply-manyfiles-') . '.zip';
    $zip = new \ZipArchive();
    $zip->open($zipPath, \ZipArchive::CREATE);
    $zip->addFromString('repo-abc123/__apply_test_marker__.txt', 'should not be written');
    for ($i = 0; $i < $fileCount; $i++) {
        $zip->addFromString("repo-abc123/file{$i}.txt", 'x');
    }
    $zip->close();
    return $zipPath;
}

function applyMarkerPath(): string
{
    return \ESSE_ROOT . '/__apply_test_marker__.txt';
}

// Backup-ZIPs haben das Format aus Updater::createBackup(): "files/<rel>" pro Datei, optional
// "database.sql". Kein "database.sql" hier, damit ein abgelehntes ZIP nie bis zum DB-Import kommt
// (der in der reinen Unit-Test-Umgebung ohne DB-Verbindung fehlschlagen wuerde) - genau das
// belegt auch, dass checkZipLimits() vor jedem Import/Schreiben greift.
function restoreMarkerPath(): string
{
    return \ESSE_ROOT . '/__restore_test_marker__.txt';
}

function makeBackupZipWithSymlink(): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-restore-symlink-') . '.zip';
    $zip = new \ZipArchive();
    $zip->open($zipPath, \ZipArchive::CREATE);
    $zip->addFromString('files/__restore_test_marker__.txt', 'should not be written');
    $zip->addFromString('files/evil-link', '/etc/passwd');
    $idx = $zip->locateName('files/evil-link');
    $zip->setExternalAttributesIndex($idx, \ZipArchive::OPSYS_UNIX, 0120777 << 16); // S_IFLNK
    $zip->close();
    return $zipPath;
}

function makeBackupZipWithOversizedFile(int $mb): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-restore-bigfile-') . '.zip';
    $zip = new \ZipArchive();
    $zip->open($zipPath, \ZipArchive::CREATE);
    $zip->addFromString('files/__restore_test_marker__.txt', 'should not be written');
    $zip->addFromString('files/big.bin', str_repeat("\0", $mb * 1024 * 1024));
    $zip->setCompressionName('files/big.bin', \ZipArchive::CM_DEFLATE, 9);
    $zip->close();
    return $zipPath;
}

return [
    'isNewer: hoehere Version wird erkannt' => function () {
        Assert::true(Updater::isNewer('0.2.1-alpha', '0.2.0-alpha'));
    },
    'isNewer: gleiche Version ist nicht neuer' => function () {
        Assert::false(Updater::isNewer('0.2.0-alpha', '0.2.0-alpha'));
    },
    'isNewer: niedrigere Version ist nicht neuer' => function () {
        Assert::false(Updater::isNewer('0.1.9-alpha', '0.2.0-alpha'));
    },
    'isNewer: fuehrendes "v" wird ignoriert' => function () {
        Assert::true(Updater::isNewer('v0.3.0-alpha', '0.2.1-alpha'));
    },
    'isNewer: "-dev"-Suffix der lokalen Version wird ignoriert' => function () {
        Assert::false(Updater::isNewer('0.2.1-alpha', '0.2.1-alpha-dev'));
    },

    'removeOrphanedFiles: entfernt Dateien, die nicht im Backup waren (z.B. neue Uploads)' => function () {
        $root = sys_get_temp_dir() . '/esse-restore-test-' . bin2hex(random_bytes(4));
        makeFile($root . '/plugins/keep-plugin/file.php');
        makeFile($root . '/public/uploads/orphan.txt');
        try {
            $removed = callRemoveOrphanedFiles($root, ['plugins/keep-plugin/file.php' => true]);
            Assert::same(1, $removed);
            Assert::true(file_exists($root . '/plugins/keep-plugin/file.php'), 'Datei aus dem Backup darf nicht geloescht werden');
            Assert::true(!file_exists($root . '/public/uploads/orphan.txt'), 'Datei, die nicht im Backup war, muss entfernt werden');
        } finally {
            rrmdir($root);
        }
    },

    'removeOrphanedFiles: raeumt leere Verzeichnisse nach dem Entfernen auf' => function () {
        $root = sys_get_temp_dir() . '/esse-restore-test-' . bin2hex(random_bytes(4));
        makeFile($root . '/plugins/orphan-plugin/file.php');
        try {
            callRemoveOrphanedFiles($root, []);
            Assert::true(!is_dir($root . '/plugins/orphan-plugin'), 'Leeres Verzeichnis sollte nach dem Aufraeumen weg sein');
        } finally {
            rrmdir($root);
        }
    },

    'removeOrphanedFiles: ruehrt BACKUP_EXCLUDED-Pfade (public/vendor/) nie an' => function () {
        $root = sys_get_temp_dir() . '/esse-restore-test-' . bin2hex(random_bytes(4));
        makeFile($root . '/public/vendor/some-lib/lib.js');
        try {
            callRemoveOrphanedFiles($root, []);
            Assert::true(file_exists($root . '/public/vendor/some-lib/lib.js'), 'public/vendor/ wird nie von Backups erfasst und darf beim Aufraeumen nicht geloescht werden');
        } finally {
            rrmdir($root);
        }
    },

    'removeOrphanedFiles: ruehrt geschuetzte Pfade (config/, local.php, storage/cache/, storage/backups/) nie an' => function () {
        $root = sys_get_temp_dir() . '/esse-restore-test-' . bin2hex(random_bytes(4));
        makeFile($root . '/config/config.php');
        makeFile($root . '/local.php');
        makeFile($root . '/storage/cache/foo.json');
        makeFile($root . '/storage/backups/old.zip');
        try {
            callRemoveOrphanedFiles($root, []);
            Assert::true(file_exists($root . '/config/config.php'));
            Assert::true(file_exists($root . '/local.php'));
            Assert::true(file_exists($root . '/storage/cache/foo.json'));
            Assert::true(file_exists($root . '/storage/backups/old.zip'));
        } finally {
            rrmdir($root);
        }
    },

    'removeOrphanedFiles: entfernt verwaiste Dateien unter storage/uploads/ (private Medien)' => function () {
        // storage/uploads/ ist bewusst NICHT geschuetzt/ausgeschlossen — dort liegen private
        // Mediendateien (Esse\Media), die genauso wie public/uploads/ am Restore teilnehmen
        // muessen sollen.
        $root = sys_get_temp_dir() . '/esse-restore-test-' . bin2hex(random_bytes(4));
        makeFile($root . '/storage/uploads/orphan-private.txt');
        try {
            $removed = callRemoveOrphanedFiles($root, []);
            Assert::same(1, $removed);
            Assert::true(!file_exists($root . '/storage/uploads/orphan-private.txt'), 'storage/uploads/ sollte am Aufraeumen teilnehmen, nicht geschuetzt sein');
        } finally {
            rrmdir($root);
        }
    },

    // -- apply() (CMS-Selbst-Update): dieselbe Vorpruefung wie packageInstallZip(), siehe
    // PackageInstallTest.php fuer die analogen Plugin/Theme/Iconpack-Faelle. --

    'apply: lehnt Update-ZIP mit Symlink ab und schreibt nichts' => function () {
        $zipPath = makeUpdateZipWithSymlink();
        @unlink(applyMarkerPath());

        $threw = false;
        try {
            Updater::apply($zipPath, fn() => null);
        } catch (\RuntimeException $e) {
            $threw = true;
            Assert::true(str_contains($e->getMessage(), 'Symlink'), "Fehlermeldung sollte Symlink nennen, war: {$e->getMessage()}");
        } finally {
            @unlink($zipPath);
        }
        Assert::true($threw, 'apply() sollte bei einem Symlink im Update-ZIP eine Exception werfen');
        Assert::true(!file_exists(applyMarkerPath()), 'Bei abgelehntem Update darf ueberhaupt nichts geschrieben werden');
    },

    'apply: lehnt Update-ZIP mit zu großer Einzeldatei ab und schreibt nichts' => function () {
        $zipPath = makeUpdateZipWithOversizedFile(25); // 25 MB, Einzeldatei-Limit liegt bei 20 MB
        @unlink(applyMarkerPath());

        $threw = false;
        try {
            Updater::apply($zipPath, fn() => null);
        } catch (\RuntimeException $e) {
            $threw = true;
            Assert::true(str_contains($e->getMessage(), 'groß'), "Fehlermeldung sollte Größenproblem nennen, war: {$e->getMessage()}");
        } finally {
            @unlink($zipPath);
        }
        Assert::true($threw, 'apply() sollte eine zu große Einzeldatei ablehnen');
        Assert::true(!file_exists(applyMarkerPath()), 'Bei abgelehntem Update darf ueberhaupt nichts geschrieben werden');
    },

    'apply: lehnt Update-ZIP mit zu vielen Dateien ab und schreibt nichts' => function () {
        $zipPath = makeUpdateZipWithManyFiles(1100); // Limit liegt bei 1000
        @unlink(applyMarkerPath());

        $threw = false;
        try {
            Updater::apply($zipPath, fn() => null);
        } catch (\RuntimeException $e) {
            $threw = true;
            Assert::true(str_contains($e->getMessage(), 'viele Dateien'), "Fehlermeldung sollte Dateianzahl nennen, war: {$e->getMessage()}");
        } finally {
            @unlink($zipPath);
        }
        Assert::true($threw, 'apply() sollte zu viele Dateien ablehnen');
        Assert::true(!file_exists(applyMarkerPath()), 'Bei abgelehntem Update darf ueberhaupt nichts geschrieben werden');
    },

    // -- restore() (Backup-Wiederherstellung): gleiche Vorpruefung wie apply(), aber mit den
    // grosszuegigeren BACKUP_*-Grenzwerten (ein Backup enthaelt einen vollen DB-Dump + Uploads). --

    'restore: lehnt Backup-ZIP mit Symlink ab, vor DB-Import und vor jedem Schreiben' => function () {
        $zipPath = makeBackupZipWithSymlink();
        @unlink(restoreMarkerPath());

        $threw = false;
        try {
            Updater::restore($zipPath, fn() => null);
        } catch (\RuntimeException $e) {
            $threw = true;
            Assert::true(str_contains($e->getMessage(), 'Symlink'), "Fehlermeldung sollte Symlink nennen, war: {$e->getMessage()}");
        } finally {
            @unlink($zipPath);
        }
        Assert::true($threw, 'restore() sollte bei einem Symlink im Backup-ZIP eine Exception werfen');
        Assert::true(!file_exists(restoreMarkerPath()), 'Bei abgelehntem Backup darf ueberhaupt nichts geschrieben werden');
    },

    'restore: lehnt Backup-ZIP mit zu großer Einzeldatei ab (Backup-Limit, nicht Paket-Limit)' => function () {
        // 210 MB liegt ueber dem Backup-Limit (200 MB), aber weit ueber dem Paket-Limit (20 MB) -
        // belegt, dass restore() tatsaechlich die groesseren BACKUP_*-Grenzwerte nutzt.
        $zipPath = makeBackupZipWithOversizedFile(210);
        @unlink(restoreMarkerPath());

        $threw = false;
        try {
            Updater::restore($zipPath, fn() => null);
        } catch (\RuntimeException $e) {
            $threw = true;
            Assert::true(str_contains($e->getMessage(), 'groß'), "Fehlermeldung sollte Größenproblem nennen, war: {$e->getMessage()}");
        } finally {
            @unlink($zipPath);
        }
        Assert::true($threw, 'restore() sollte eine 210-MB-Einzeldatei ablehnen (ueber dem Backup-Limit von 200 MB)');
        Assert::true(!file_exists(restoreMarkerPath()), 'Bei abgelehntem Backup darf ueberhaupt nichts geschrieben werden');
    },

    'restore: akzeptiert 50 MB Einzeldatei (ueber Paket-Limit von 20 MB, unter Backup-Limit von 200 MB)' => function () {
        // Belegt per checkZipLimits() direkt (ohne echtes Schreiben in ESSE_ROOT), dass restore()
        // mit den BACKUP_*-Grenzwerten tatsaechlich mehr erlaubt als die engen Paket-Grenzwerte.
        $zipPath = makeBackupZipWithOversizedFile(50);
        $zip = new \ZipArchive();
        $zip->open($zipPath);
        try {
            $pkgError = Updater::checkZipLimits($zip, $zipPath);
            Assert::true($pkgError !== null, 'Mit den Paket-Limits (20 MB) sollten 50 MB abgelehnt werden');

            $backupError = Updater::checkZipLimits(
                $zip, $zipPath, null,
                Updater::BACKUP_MAX_ZIP_BYTES, Updater::BACKUP_MAX_FILES, Updater::BACKUP_MAX_FILE_BYTES, Updater::BACKUP_MAX_TOTAL_BYTES
            );
            Assert::true($backupError === null, 'Mit den Backup-Limits (200 MB) sollten 50 MB akzeptiert werden, Fehler war: ' . ($backupError ?? ''));
        } finally {
            $zip->close();
            @unlink($zipPath);
        }
    },

    // -- Path-Traversal: fail-closed statt einzelnen Eintrag beim Schreiben zu uebergehen --

    'apply: lehnt Update-ZIP mit Path-Traversal-Eintrag komplett ab (fail-closed)' => function () {
        $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-apply-traversal-') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('repo-abc123/__apply_test_marker__.txt', 'should not be written');
        $zip->addFromString('repo-abc123/../../outside.txt', 'escape attempt');
        $zip->close();
        @unlink(applyMarkerPath());

        $threw = false;
        try {
            Updater::apply($zipPath, fn() => null);
        } catch (\RuntimeException $e) {
            $threw = true;
            Assert::true(str_contains($e->getMessage(), 'Traversal') || str_contains($e->getMessage(), 'verdächtigen Pfad'), "Fehlermeldung sollte Traversal nennen, war: {$e->getMessage()}");
        } finally {
            @unlink($zipPath);
        }
        Assert::true($threw, 'apply() sollte das gesamte ZIP ablehnen, nicht nur den Traversal-Eintrag uebergehen');
        Assert::true(!file_exists(applyMarkerPath()), 'Bei abgelehntem Update darf ueberhaupt nichts geschrieben werden, auch nicht die unverdaechtigen Dateien daneben');
    },

    'restore: lehnt Backup-ZIP mit Path-Traversal-Eintrag komplett ab (fail-closed)' => function () {
        $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-restore-traversal-') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('files/__restore_test_marker__.txt', 'should not be written');
        $zip->addFromString('files/../../outside.txt', 'escape attempt');
        $zip->close();
        @unlink(restoreMarkerPath());

        $threw = false;
        try {
            Updater::restore($zipPath, fn() => null);
        } catch (\RuntimeException $e) {
            $threw = true;
            Assert::true(str_contains($e->getMessage(), 'Traversal') || str_contains($e->getMessage(), 'verdächtigen Pfad'), "Fehlermeldung sollte Traversal nennen, war: {$e->getMessage()}");
        } finally {
            @unlink($zipPath);
        }
        Assert::true($threw, 'restore() sollte das gesamte Backup-ZIP ablehnen, nicht nur den Traversal-Eintrag uebergehen');
        Assert::true(!file_exists(restoreMarkerPath()), 'Bei abgelehntem Backup darf ueberhaupt nichts geschrieben werden, auch nicht die unverdaechtigen Dateien daneben');
    },
];
