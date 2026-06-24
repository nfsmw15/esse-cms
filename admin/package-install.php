<?php

declare(strict_types=1);

/**
 * Shared ZIP installer for plugins, themes and icon packs.
 * Include this file then call packageInstallZip($tmpFile, 'plugin'|'theme'|'iconpack').
 *
 * Die Groessen-/Symlink-Vorpruefung selbst lebt in \Esse\Updater::checkZipLimits() — dieselbe
 * Pruefung, die auch Updater::apply() (CMS-Selbst-Update) vor dem Schreiben durchlaeuft.
 */

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

// Prueft, ob der Owner aus "owner/repo" ein aktiver, konfigurierter Kanal ist
// (core/Schema.php: repo_channels) — unabhaengig vom trusted-Flag, das nur die Darstellung im
// "Verfuegbar"-Tab beeinflusst, nicht den Zugriff selbst. manage_plugins/manage_themes/
// manage_repos sind getrennte Berechtigungen: ohne diese Pruefung liesse sich
// install_from_repo mit jedem beliebigen GitHub-owner/repo aufrufen, auch wenn die
// Kanalverwaltung (/admin/repos) fuer den aktuellen Nutzer gesperrt ist.
function packageRepoChannelAllowed(string $fullName): bool
{
    $owner = explode('/', $fullName, 2)[0] ?? '';
    if ($owner === '') return false;
    $tr = \Esse\DB::table('repo_channels');
    return \Esse\DB::fetch("SELECT id FROM `{$tr}` WHERE owner = ? AND active = 1", [$owner]) !== null;
}

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
    if (!isset(ESSE_PKG_TYPES[$type])) {
        return "Unbekannter Pakettyp '{$type}'.";
    }
    $config = ESSE_PKG_TYPES[$type];

    $zip = new \ZipArchive();
    if ($zip->open($tmpFile) !== true) return 'ZIP-Datei konnte nicht geöffnet werden.';

    $limitError = \Esse\Updater::checkZipLimits($zip, $tmpFile, $config['extensions']);
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
        if ($content === false || strlen($content) > \Esse\Updater::MAX_FILE_BYTES) continue;

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
