<?php

declare(strict_types=1);

/**
 * Shared ZIP installer for plugins, themes and icon packs.
 * Include this file then call packageInstallZip($tmpFile, 'plugin'|'theme'|'iconpack').
 */

// Grenzwerte gegen Zip-Bomben/überdimensionierte Pakete — gelten fuer alle Paket-Typen.
const ESSE_PKG_MAX_ZIP_BYTES   = 20 * 1024 * 1024;  // 20 MB komprimiertes ZIP
const ESSE_PKG_MAX_FILES       = 1000;              // Anzahl Eintraege im ZIP
const ESSE_PKG_MAX_FILE_BYTES  = 20 * 1024 * 1024;  // 20 MB pro Einzeldatei (entpackt)
const ESSE_PKG_MAX_TOTAL_BYTES = 80 * 1024 * 1024;  // 80 MB gesamt (entpackt)

// Pro Pakettyp: Name der Metadaten-Datei im ZIP, Zielverzeichnis relativ zu ESSE_ROOT,
// Pflichtfelder in der Metadaten-Datei und optionale Endungs-Allowlist (null = keine
// Einschraenkung, z.B. Plugins/Themes brauchen .php).
const ESSE_PKG_TYPES = [
    'plugin' => [
        'meta_name'  => 'plugin.json',
        'target_dir' => 'plugins',
        'required'   => ['name'],
        'extensions' => null,
    ],
    'theme' => [
        'meta_name'  => 'theme.json',
        'target_dir' => 'themes',
        'required'   => ['name'],
        'extensions' => null,
    ],
    'iconpack' => [
        'meta_name'  => 'iconpack.json',
        'target_dir' => 'public/vendor',
        'required'   => ['name', 'css'],
        // Icon-Packs brauchen nie mehr als Metadaten, Stylesheets, Fonts und Bilder — alles
        // andere (insbesondere .php) wird komplett abgelehnt, nicht nur einzeln ausgefiltert.
        'extensions' => ['json', 'css', 'map', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'svg', 'png', 'gif', 'jpg', 'jpeg', 'webp'],
    ],
];

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

// Prueft Groesse, Dateianzahl, Symlinks/Spezialdateien und optional eine Endungs-Allowlist
// VOR dem Dekomprimieren (anhand der Central-Directory-Metadaten) — wird von einem
// einzigen verdaechtigen Eintrag das GANZE Paket abgelehnt, nicht nur dieser Eintrag.
function packageCheckZipLimits(\ZipArchive $zip, string $tmpFile, ?array $allowedExtensions = null): ?string
{
    if (filesize($tmpFile) > ESSE_PKG_MAX_ZIP_BYTES) {
        return 'ZIP-Datei zu groß (max. ' . (int) (ESSE_PKG_MAX_ZIP_BYTES / 1024 / 1024) . ' MB).';
    }
    if ($zip->numFiles > ESSE_PKG_MAX_FILES) {
        return 'ZIP enthält zu viele Dateien (max. ' . ESSE_PKG_MAX_FILES . ').';
    }

    $totalBytes = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) continue;
        $name = $stat['name'];
        $isDir = str_ends_with($name, '/');

        // Symlinks/Spezialdateien (Block-/Char-Devices, FIFOs, Sockets) — erkannt anhand des
        // Unix-Modes in den External Attributes — lehnen das GESAMTE Paket ab, kein stilles
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

        if ($stat['size'] > ESSE_PKG_MAX_FILE_BYTES) {
            return 'Einzeldatei im ZIP zu groß (max. ' . (int) (ESSE_PKG_MAX_FILE_BYTES / 1024 / 1024) . ' MB): ' . basename($name);
        }
        $totalBytes += $stat['size'];
        if ($totalBytes > ESSE_PKG_MAX_TOTAL_BYTES) {
            return 'ZIP zu groß entpackt (max. ' . (int) (ESSE_PKG_MAX_TOTAL_BYTES / 1024 / 1024) . ' MB gesamt) — möglicher Zip-Bomb.';
        }
    }

    return null;
}

function packageInstallZip(string $tmpFile, string $type): array|string
{
    if (!isset(ESSE_PKG_TYPES[$type])) {
        return "Unbekannter Pakettyp '{$type}'.";
    }
    $config = ESSE_PKG_TYPES[$type];

    $zip = new \ZipArchive();
    if ($zip->open($tmpFile) !== true) return 'ZIP-Datei konnte nicht geöffnet werden.';

    $limitError = packageCheckZipLimits($zip, $tmpFile, $config['extensions']);
    if ($limitError !== null) {
        $zip->close();
        return $limitError;
    }

    $metaJson = null;
    $rootDir  = null;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (basename($name) === $config['meta_name']) {
            $metaJson = json_decode($zip->getFromIndex($i), true);
            $parts    = explode('/', $name);
            $rootDir  = count($parts) > 1 ? $parts[0] : '';
            break;
        }
    }

    $missingMeta = !$metaJson;
    foreach ($config['required'] as $field) {
        if (empty($metaJson[$field] ?? null)) $missingMeta = true;
    }
    if ($missingMeta) {
        $zip->close();
        return "Keine gültige {$config['meta_name']} im ZIP gefunden.";
    }

    // Validate slug: must be non-empty, lowercase alphanumeric + hyphens, 2-64 chars
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($metaJson['name']));
    if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,63}$/', $slug)) {
        $zip->close();
        return "Ungültiger Paketname '{$metaJson['name']}' — erlaubt sind Kleinbuchstaben, Ziffern und Bindestriche.";
    }

    $baseDir = realpath(ESSE_ROOT . '/' . $config['target_dir']);
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

        // Defense-in-depth: tatsaechlich dekomprimierte Groesse erneut gegen das Limit
        // pruefen, falls Central-Directory- und Local-File-Header-Metadaten abweichen.
        $content = $zip->getFromIndex($i);
        if ($content === false || strlen($content) > ESSE_PKG_MAX_FILE_BYTES) continue;

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
