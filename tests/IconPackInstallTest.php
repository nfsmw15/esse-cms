<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/iconpack-install.php';

function cleanupIconPackDir(string $slug): void
{
    $dir = dirname(__DIR__) . '/public/vendor/' . $slug;
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
    'installIconPack: lehnt ZIP mit PHP-Datei ab (RCE-Schutz)' => function () {
        $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-iconpack-rce-') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('evil-pack/iconpack.json', json_encode([
            'name' => 'evil-pack', 'version' => '1.0.0', 'prefix' => 'evil', 'css' => 'evil.css',
        ]));
        $zip->addFromString('evil-pack/evil.css', '.evil {}');
        $zip->addFromString('evil-pack/probe.php', '<?php echo "pwned"; ?>');
        $zip->close();

        $result = installIconPack($zipPath);
        @unlink($zipPath);

        try {
            Assert::true(is_string($result), 'Erwartet Fehlermeldung (String), bekam: ' . var_export($result, true));
            Assert::true(str_contains($result, 'Dateityp nicht erlaubt'), "Fehlermeldung sollte Dateityp nennen, war: {$result}");
            Assert::true(!is_file(dirname(__DIR__) . '/public/vendor/evil-pack/probe.php'), 'probe.php darf nicht auf dem Server liegen');
            Assert::true(!is_dir(dirname(__DIR__) . '/public/vendor/evil-pack'), 'Icon-Pack mit PHP-Datei darf nicht installiert worden sein');
        } finally {
            cleanupIconPackDir('evil-pack');
        }
    },

    'installIconPack: akzeptiert ein normales Icon-Pack (json/css/woff)' => function () {
        $zipPath = tempnam(sys_get_temp_dir(), 'esse-test-iconpack-ok-') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('good-pack/iconpack.json', json_encode([
            'name' => 'good-pack', 'version' => '1.0.0', 'prefix' => 'good', 'css' => 'good.css',
        ]));
        $zip->addFromString('good-pack/good.css', '.good {}');
        $zip->addFromString('good-pack/font.woff2', 'binary-font-data');
        $zip->close();

        $result = installIconPack($zipPath);
        @unlink($zipPath);

        try {
            Assert::true(is_array($result), 'Erwartet Erfolg (Array), bekam: ' . var_export($result, true));
            Assert::same('good-pack', $result['name'] ?? null);
            Assert::true(is_file(dirname(__DIR__) . '/public/vendor/good-pack/good.css'), 'CSS-Datei sollte installiert worden sein');
        } finally {
            cleanupIconPackDir('good-pack');
        }
    },
];
