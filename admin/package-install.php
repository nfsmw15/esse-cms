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
    // Limits gegen Zip-Bomben/überdimensionierte Pakete — Plugins/Themes sind normalerweise
    // wenige MB groß, diese Werte sind bewusst großzügig, aber endlich.
    $maxZipBytes   = 50 * 1024 * 1024;   // 50 MB komprimiertes ZIP
    $maxFiles      = 5000;               // Anzahl Einträge im ZIP
    $maxFileBytes  = 50 * 1024 * 1024;   // 50 MB pro Einzeldatei (entpackt)
    $maxTotalBytes = 200 * 1024 * 1024;  // 200 MB gesamt (entpackt)

    if (filesize($tmpFile) > $maxZipBytes) {
        return 'ZIP-Datei zu groß (max. ' . (int) ($maxZipBytes / 1024 / 1024) . ' MB).';
    }

    $zip = new \ZipArchive();
    if ($zip->open($tmpFile) !== true) return 'ZIP-Datei konnte nicht geöffnet werden.';

    if ($zip->numFiles > $maxFiles) {
        $zip->close();
        return "ZIP enthält zu viele Dateien (max. {$maxFiles}).";
    }

    // Entpackte Größen vorab aus dem Central Directory prüfen, ohne zu dekomprimieren —
    // schützt vor Zip-Bomben (winzige Datei, riesiger entpackter Inhalt).
    $totalBytes = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) continue;
        if ($stat['size'] > $maxFileBytes) {
            $zip->close();
            return 'Einzeldatei im ZIP zu groß (max. ' . (int) ($maxFileBytes / 1024 / 1024) . ' MB): ' . basename($stat['name']);
        }
        $totalBytes += $stat['size'];
        if ($totalBytes > $maxTotalBytes) {
            $zip->close();
            return 'ZIP zu groß entpackt (max. ' . (int) ($maxTotalBytes / 1024 / 1024) . ' MB gesamt) — möglicher Zip-Bomb.';
        }
    }

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

    // Validate slug: must be non-empty, lowercase alphanumeric + hyphens, 2-64 chars
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($metaJson['name']));
    if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,63}$/', $slug)) {
        $zip->close();
        return "Ungültiger Paketname '{$metaJson['name']}' — erlaubt sind Kleinbuchstaben, Ziffern und Bindestriche.";
    }

    $baseDir = realpath(ESSE_ROOT . '/' . ($type === 'plugin' ? 'plugins' : 'themes'));
    if (!$baseDir) {
        $zip->close();
        return "Zielverzeichnis nicht gefunden.";
    }

    $target = $baseDir . DIRECTORY_SEPARATOR . $slug;

    // Path traversal guard: ensure target stays within allowed base directory
    if (strpos($target . DIRECTORY_SEPARATOR, $baseDir . DIRECTORY_SEPARATOR) !== 0) {
        $zip->close();
        return "Ungültiger Zielpfad — möglicher Path-Traversal-Angriff.";
    }

    $isUpdate = is_dir($target);

    // Extract to a temp directory first
    $tmp = $target . '_tmp_' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $rel  = $rootDir ? preg_replace('#^' . preg_quote($rootDir, '#') . '/?#', '', $name) : $name;
        if ($rel === '' || str_ends_with($rel, '/') || str_contains($rel, '..')) continue;

        // Symlinks/Spezialdateien (Unix-Mode in den External Attributes) überspringen.
        // file_put_contents() wuerde ohnehin nie einen echten Symlink erzeugen, sondern nur
        // den Linktext als Dateiinhalt schreiben — trotzdem nicht aufnehmen.
        if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX) {
            $unixMode = ($attr >> 16) & 0xF000;
            if ($unixMode === 0xA000) continue; // S_IFLNK
        }

        // Defense-in-depth: tatsaechlich dekomprimierte Groesse erneut gegen das Limit
        // pruefen, falls Central-Directory- und Local-File-Header-Metadaten abweichen.
        $content = $zip->getFromIndex($i);
        if ($content === false || strlen($content) > $maxFileBytes) continue;

        $dest = $tmp . '/' . $rel;
        if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
        file_put_contents($dest, $content);
    }

    $zip->close();

    if ($isUpdate) {
        packageDeleteDir($target);
    }
    rename($tmp, $target);

    $metaJson['_updated'] = $isUpdate;
    return $metaJson;
}
