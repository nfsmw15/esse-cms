<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/package-install.php';

// Baut ein ZIP mit einer stark komprimierbaren Datei von $uncompressedMb MB entpackter Größe —
// simuliert eine Zip-Bombe (winzige ZIP-Datei, riesiger entpackter Inhalt).
function makeBombZip(int $uncompressedMb): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-bomb-') . '.zip';
    $zip = new \ZipArchive();
    $zip->open($zipPath, \ZipArchive::CREATE);
    $zip->addFromString('bomb-test/plugin.json', json_encode(['name' => 'bomb-test', 'version' => '1.0.0']));
    $zip->addFromString('bomb-test/Plugin.php', '<?php class BombTest {}');
    $zip->addFromString('bomb-test/big.bin', str_repeat("\0", $uncompressedMb * 1024 * 1024));
    $zip->setCompressionName('bomb-test/big.bin', \ZipArchive::CM_DEFLATE, 9);
    $zip->close();

    return $zipPath;
}

// Baut ein ZIP mit mehreren Dateien, jede unter dem Einzeldatei-Limit, deren Summe aber das
// Gesamt-Limit überschreitet (Szenario aus dem Pentest: 3x 45 MB statt 1x große Datei).
function makeMultiFileBombZip(int $fileCount, int $eachMb): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-multibomb-') . '.zip';
    $zip = new \ZipArchive();
    $zip->open($zipPath, \ZipArchive::CREATE);
    $zip->addFromString('multibomb-test/plugin.json', json_encode(['name' => 'multibomb-test', 'version' => '1.0.0']));
    for ($i = 0; $i < $fileCount; $i++) {
        $name = "multibomb-test/big{$i}.bin";
        $zip->addFromString($name, str_repeat("\0", $eachMb * 1024 * 1024));
        $zip->setCompressionName($name, \ZipArchive::CM_DEFLATE, 9);
    }
    $zip->close();

    return $zipPath;
}

// Baut ein ZIP mit einem Symlink-Eintrag (Unix-External-Attributes auf S_IFLNK gesetzt).
function makeSymlinkZip(): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-symlink-') . '.zip';
    $zip = new \ZipArchive();
    $zip->open($zipPath, \ZipArchive::CREATE);
    $zip->addFromString('symlink-test/plugin.json', json_encode(['name' => 'symlink-test', 'version' => '1.0.0']));
    $zip->addFromString('symlink-test/evil-link', '/etc/passwd');
    $idx = $zip->locateName('symlink-test/evil-link');
    $unixMode = 0120777; // S_IFLNK + Permissions 0777
    $zip->setExternalAttributesIndex($idx, \ZipArchive::OPSYS_UNIX, $unixMode << 16);
    $zip->close();

    return $zipPath;
}

function cleanupPackageDir(string $slug): void
{
    $dir = dirname(__DIR__) . '/plugins/' . $slug;
    if (!is_dir($dir)) return;
    foreach (new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    ) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

return [
    'packageInstallZip: lehnt Zip-Bomb (zu große entpackte Einzeldatei) ab' => function () {
        $zipPath = makeBombZip(30); // 30 MB entpackt, Einzeldatei-Limit liegt bei 20 MB

        $result = packageInstallZip($zipPath, 'plugin');
        @unlink($zipPath);

        Assert::true(is_string($result), 'Erwartet Fehlermeldung (String), bekam: ' . var_export($result, true));
        Assert::true(
            str_contains($result, 'groß') || str_contains($result, 'Zip-Bomb'),
            "Fehlermeldung sollte Größenproblem nennen, war: {$result}"
        );
        Assert::true(!is_dir(dirname(__DIR__) . '/plugins/bomb-test'), 'Zip-Bomb darf nicht installiert worden sein');
    },

    'packageInstallZip: lehnt Gesamtgröße ueber dem Limit ab, auch wenn jede Einzeldatei klein ist' => function () {
        // 5 x 17 MB = 85 MB > 80 MB Gesamtlimit, aber jede Datei < 20 MB Einzellimit.
        $zipPath = makeMultiFileBombZip(5, 17);

        $result = packageInstallZip($zipPath, 'plugin');
        @unlink($zipPath);

        Assert::true(is_string($result), 'Erwartet Fehlermeldung (String), bekam: ' . var_export($result, true));
        Assert::true(str_contains($result, 'Zip-Bomb') || str_contains($result, 'gesamt'), "Fehlermeldung sollte Gesamtgröße nennen, war: {$result}");
        Assert::true(!is_dir(dirname(__DIR__) . '/plugins/multibomb-test'), 'Paket darf nicht installiert worden sein');
    },

    'packageInstallZip: lehnt ZIP mit zu vielen Dateien ab' => function () {
        $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-manyfiles-') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('manyfiles-test/plugin.json', json_encode(['name' => 'manyfiles-test', 'version' => '1.0.0']));
        for ($i = 0; $i < 1001; $i++) {
            $zip->addFromString("manyfiles-test/file{$i}.txt", 'x');
        }
        $zip->close();

        $result = packageInstallZip($zipPath, 'plugin');
        @unlink($zipPath);

        Assert::true(is_string($result), 'Erwartet Fehlermeldung (String), bekam: ' . var_export($result, true));
        Assert::true(str_contains($result, 'viele Dateien'), "Fehlermeldung sollte Dateianzahl nennen, war: {$result}");
    },

    'packageInstallZip: lehnt Symlink-Eintrag ab (gesamtes Paket, nicht nur der Eintrag)' => function () {
        $zipPath = makeSymlinkZip();

        $result = packageInstallZip($zipPath, 'plugin');
        @unlink($zipPath);

        Assert::true(is_string($result), 'Erwartet Fehlermeldung (String), bekam: ' . var_export($result, true));
        Assert::true(str_contains($result, 'Symlink'), "Fehlermeldung sollte Symlink nennen, war: {$result}");
        Assert::true(!is_dir(dirname(__DIR__) . '/plugins/symlink-test'), 'Paket mit Symlink darf nicht installiert worden sein');
    },

    'packageInstallZip: akzeptiert ein normal großes Paket' => function () {
        $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-normal-') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('normal-test/plugin.json', json_encode(['name' => 'normal-test', 'version' => '1.0.0']));
        $zip->addFromString('normal-test/Plugin.php', '<?php class NormalTest {}');
        $zip->close();

        $result = packageInstallZip($zipPath, 'plugin');
        @unlink($zipPath);

        try {
            Assert::true(is_array($result), 'Erwartet Erfolg (Array), bekam: ' . var_export($result, true));
            Assert::same('normal-test', $result['name'] ?? null);
        } finally {
            cleanupPackageDir('normal-test');
        }
    },
];
