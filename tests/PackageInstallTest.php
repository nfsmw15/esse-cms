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

return [
    'packageInstallZip: lehnt Zip-Bomb (zu große entpackte Einzeldatei) ab' => function () {
        $zipPath = makeBombZip(60); // 60 MB entpackt, Limit liegt bei 50 MB pro Datei

        $result = packageInstallZip($zipPath, 'plugin');
        @unlink($zipPath);

        Assert::true(is_string($result), 'Erwartet Fehlermeldung (String), bekam: ' . var_export($result, true));
        Assert::true(
            str_contains($result, 'groß') || str_contains($result, 'Zip-Bomb'),
            "Fehlermeldung sollte Größenproblem nennen, war: {$result}"
        );
        Assert::true(!is_dir(dirname(__DIR__) . '/plugins/bomb-test'), 'Zip-Bomb darf nicht installiert worden sein');
    },

    'packageInstallZip: lehnt ZIP mit zu vielen Dateien ab' => function () {
        $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-manyfiles-') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('manyfiles-test/plugin.json', json_encode(['name' => 'manyfiles-test', 'version' => '1.0.0']));
        for ($i = 0; $i < 5001; $i++) {
            $zip->addFromString("manyfiles-test/file{$i}.txt", 'x');
        }
        $zip->close();

        $result = packageInstallZip($zipPath, 'plugin');
        @unlink($zipPath);

        Assert::true(is_string($result), 'Erwartet Fehlermeldung (String), bekam: ' . var_export($result, true));
        Assert::true(str_contains($result, 'viele Dateien'), "Fehlermeldung sollte Dateianzahl nennen, war: {$result}");
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

        $pluginDir = dirname(__DIR__) . '/plugins/normal-test';
        try {
            Assert::true(is_array($result), 'Erwartet Erfolg (Array), bekam: ' . var_export($result, true));
            Assert::same('normal-test', $result['name'] ?? null);
        } finally {
            if (is_dir($pluginDir)) {
                foreach (new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($pluginDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                ) as $f) {
                    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
                }
                rmdir($pluginDir);
            }
        }
    },
];
