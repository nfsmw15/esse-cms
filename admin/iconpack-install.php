<?php

declare(strict_types=1);

/**
 * Shared icon-pack discovery/install logic — keine Top-Level-Seiteneffekte, damit diese
 * Datei auch isoliert (z.B. in Tests) eingebunden werden kann. Route-Handling liegt in
 * admin/iconpacks.php.
 */

require_once __DIR__ . '/package-install.php';

// Discover installed icon packs
function discoverIconPacks(): array
{
    $packs = [];
    foreach (glob(ESSE_ROOT . '/public/vendor/*/iconpack.json') ?: [] as $jsonFile) {
        $meta = json_decode(file_get_contents($jsonFile), true);
        if (!empty($meta['name'])) {
            $dir = basename(dirname($jsonFile));
            $meta['dir']     = $dir;
            $meta['css_url'] = '/public/vendor/' . $dir . '/' . ($meta['css'] ?? '');
            $packs[$meta['name']] = $meta;
        }
    }
    return $packs;
}

function installIconPack(string $tmpFile): array|string
{
    // Icon-Packs brauchen nie mehr als Metadaten, Stylesheets, Fonts und Bilder — alles
    // andere (insbesondere .php) wird komplett abgelehnt, nicht nur einzeln ausgefiltert.
    $allowedExt = ['json', 'css', 'map', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'svg', 'png', 'gif', 'jpg', 'jpeg', 'webp'];

    $zip = new \ZipArchive();
    if ($zip->open($tmpFile) !== true) return 'ZIP konnte nicht geöffnet werden.';

    $limitError = packageCheckZipLimits($zip, $tmpFile, $allowedExt);
    if ($limitError !== null) {
        $zip->close();
        return $limitError;
    }

    // Find iconpack.json
    $meta    = null;
    $rootDir = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (basename($name) === 'iconpack.json') {
            $meta    = json_decode($zip->getFromIndex($i), true);
            $parts   = explode('/', $name);
            $rootDir = count($parts) > 1 ? $parts[0] : '';
            break;
        }
    }

    if (!$meta || empty($meta['name']) || empty($meta['css'])) {
        $zip->close();
        return 'Keine gültige iconpack.json im ZIP gefunden.';
    }

    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($meta['name']));
    if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,63}$/', $slug)) {
        $zip->close();
        return "Ungültiger Pack-Name '{$meta['name']}'.";
    }

    $target = ESSE_ROOT . '/public/vendor/' . $slug;
    $tmp    = $target . '_tmp_' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $rel  = $rootDir ? preg_replace('#^' . preg_quote($rootDir, '#') . '/?#', '', $name) : $name;
        if ($rel === '' || str_ends_with($rel, '/') || str_contains($rel, '..')) continue;

        $content = $zip->getFromIndex($i);
        if ($content === false || strlen($content) > ESSE_PKG_MAX_FILE_BYTES) continue;

        $dest = $tmp . '/' . $rel;
        if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
        file_put_contents($dest, $content);
    }
    $zip->close();

    if (is_dir($target)) packageDeleteDir($target);
    rename($tmp, $target);

    return $meta;
}
