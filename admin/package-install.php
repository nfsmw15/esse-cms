<?php

declare(strict_types=1);

/**
 * Shared ZIP installer for plugins and themes.
 * Include this file then call packageInstallZip($tmpFile, 'plugin'|'theme').
 */

function packageDeleteDir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    ) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function packageInstallZip(string $tmpFile, string $type): array|string
{
    $zip = new \ZipArchive();
    if ($zip->open($tmpFile) !== true) return 'ZIP-Datei konnte nicht geöffnet werden.';

    $metaName = $type === 'plugin' ? 'plugin.json' : 'theme.json';
    $metaJson = null;
    $rootDir  = null;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (basename($name) === $metaName) {
            $metaJson = json_decode($zip->getFromIndex($i), true);
            $parts    = explode('/', $name);
            $rootDir  = count($parts) > 1 ? $parts[0] : '';
            break;
        }
    }

    if (!$metaJson || empty($metaJson['name'])) {
        $zip->close();
        return "Keine gültige {$metaName} im ZIP gefunden.";
    }

    $slug   = preg_replace('/[^a-z0-9\-]/', '', strtolower($metaJson['name']));
    $target = ESSE_ROOT . '/' . ($type === 'plugin' ? 'plugins' : 'themes') . '/' . $slug;
    $isUpdate = is_dir($target);

    // Extract to a temp directory first — safer than overwriting live files directly
    $tmp = $target . '_tmp_' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $rel  = $rootDir ? preg_replace('#^' . preg_quote($rootDir, '#') . '/?#', '', $name) : $name;
        if ($rel === '' || str_ends_with($rel, '/') || str_contains($rel, '..')) continue;

        $dest = $tmp . '/' . $rel;
        if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
        file_put_contents($dest, $zip->getFromIndex($i));
    }

    $zip->close();

    // Swap: remove old directory, move temp into place
    if ($isUpdate) {
        packageDeleteDir($target);
    }
    rename($tmp, $target);

    $metaJson['_updated'] = $isUpdate;
    return $metaJson;
}
