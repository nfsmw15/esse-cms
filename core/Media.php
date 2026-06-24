<?php

declare(strict_types=1);

namespace Esse;

class Media
{
    public const TYPES = [
        'image'    => 'Bild',
        'document' => 'Dokument',
        'file'     => 'Datei',
    ];

    public const VISIBILITY = ['public', 'private'];

    // "private" war bisher nur ein Label/Filter in der Mediathek — die Datei lag trotzdem unter
    // /public/uploads/ und war für jeden direkt per URL erreichbar. Private Dateien liegen jetzt
    // physisch außerhalb des Webroots; PRIVATE_PATH_PREFIX ist kein echter Web-Pfad, sondern nur
    // ein Marker in der DB-Spalte `path`, aufgelöst über absolutePath().
    private const PRIVATE_PATH_PREFIX = '/private-media';

    public static function migrateDb(): void
    {
        $p = defined('ESSE_DB_PREFIX') ? \ESSE_DB_PREFIX : 'esse_';
        foreach (Schema::tables($p) as $sql) {
            if (str_contains($sql, '`' . $p . 'media_folders`') || str_contains($sql, '`' . $p . 'media`')) {
                DB::query($sql);
            }
        }

        // Bestandsinstallationen: folder_id-Spalte + FK nachziehen
        $t = DB::table('media');
        $cols = DB::fetchAll("SHOW COLUMNS FROM `{$t}` LIKE 'folder_id'");
        if (!$cols) {
            DB::query("ALTER TABLE `{$t}` ADD COLUMN `folder_id` INT UNSIGNED NULL");
            DB::query("ALTER TABLE `{$t}` ADD KEY `idx_folder` (`folder_id`)");
            $tf = DB::table('media_folders');
            DB::query("ALTER TABLE `{$t}` ADD CONSTRAINT `fk_media_folder`
                        FOREIGN KEY (`folder_id`) REFERENCES `{$tf}`(`id`) ON DELETE SET NULL");
        }
    }

    // Verschiebt Bestands-Dateien, die als "private" markiert sind, aber noch unter
    // /public/uploads/ liegen, in den geschützten Speicherort. Läuft unconditional bei jedem
    // Request (Auth::syncSecurityMigrations()), nicht erst nach dem nächsten Admin-Login — sonst
    // bliebe die Sicherheitslücke bis zum nächsten Login offen (siehe Lehre aus der
    // manage_repos-Migration). Naturlich idempotent über die WHERE-Klausel, kein Flag nötig:
    // einmal verschobene Zeilen matchen das Muster danach nicht mehr.
    public static function migratePrivateFiles(): void
    {
        $t = DB::table('media');
        $rows = DB::fetchAll(
            "SELECT * FROM `{$t}` WHERE `visibility` = 'private' AND `path` LIKE '/public/uploads/%'"
        );
        foreach ($rows as $row) {
            self::setVisibility((int) $row['id'], 'private');
        }
    }

    // Schreibt das Bild unter $path serverseitig per GD neu — ein "Polyglot"-Upload (gültiges
    // Bild laut getimagesize(), aber mit zusätzlich eingebetteten Bytes, z.B. PHP-Code) besteht
    // diese Prüfung nicht: GD dekodiert nur die tatsächlichen Bilddaten und schreibt eine neue
    // Datei, alles andere geht dabei verloren. Aktuell nicht direkt ausführbar (kein bekannter
    // Weg, eine .jpg/.png als PHP auszuführen), aber Verteidigung in der Tiefe statt sich allein
    // auf "sieht aus wie ein Bild" zu verlassen.
    // Rückgabe false bedeutet: GD konnte die Datei trotz erfolgreichem getimagesize() nicht
    // dekodieren — verdächtig genug, um den Upload abzulehnen statt die Originaldatei zu behalten.
    // Animierte GIFs werden dabei auf das erste Frame reduziert (GD unterstützt keine
    // Multi-Frame-Ausgabe) — ein bewusster Kompromiss zugunsten der Härtung.
    public static function reencodeImage(string $path, string $ext): bool
    {
        if (!extension_loaded('gd')) return true; // keine Härtung möglich, Upload aber nicht hart blockieren

        $ext      = strtolower($ext);
        $createFn = match ($ext) {
            'jpg', 'jpeg' => 'imagecreatefromjpeg',
            'png'         => 'imagecreatefrompng',
            'gif'         => 'imagecreatefromgif',
            'webp'        => 'imagecreatefromwebp',
            default       => null,
        };
        if ($createFn === null || !function_exists($createFn)) return true; // Typ ohne GD-Unterstuetzung

        $image = @$createFn($path);
        if (!$image) return false;

        if (in_array($ext, ['png', 'gif', 'webp'], true)) {
            imagesavealpha($image, true);
        }

        $ok = match ($ext) {
            'jpg', 'jpeg' => imagejpeg($image, $path, 90),
            'png'         => imagepng($image, $path),
            'gif'         => imagegif($image, $path),
            'webp'        => function_exists('imagewebp') ? imagewebp($image, $path, 90) : true,
        };
        imagedestroy($image);
        return $ok;
    }

    // Löst den in `path` gespeicherten Wert in einen absoluten Dateisystempfad auf — je nach
    // Konvention im öffentlichen Webroot (/public/...) oder im geschützten Speicherort
    // (PRIVATE_PATH_PREFIX). Gibt null zurück, wenn der Pfad keiner bekannten Konvention
    // entspricht (z.B. manipulierter/fremder Wert).
    public static function absolutePath(string $path): ?string
    {
        if (str_starts_with($path, self::PRIVATE_PATH_PREFIX . '/')) {
            return self::privateDir() . substr($path, strlen(self::PRIVATE_PATH_PREFIX));
        }
        if (str_starts_with($path, '/public/')) {
            return \ESSE_ROOT . $path;
        }
        return null;
    }

    private static function privateDir(): string
    {
        // storage/uploads existiert bereits als ungenutztes Scaffolding (nur .gitkeep) — hier
        // wiederverwendet statt ein weiteres, redundantes Verzeichnis anzulegen. storage/ ist
        // bereits per .htaccess ("Require all denied") geschützt bzw. liegt produktiv ganz
        // außerhalb des Webroots (ESSE_PRIVATE_PATH).
        return \ESSE_PRIVATE_PATH . '/storage/uploads';
    }

    // Ändert die Sichtbarkeit EINER Mediendatei und verschiebt die physische Datei zwischen
    // öffentlichem Webroot und geschütztem Speicherort — die bloße DB-Spalte zu ändern würde die
    // Datei an ihrem bisherigen (öffentlichen oder geschützten) Ort liegen lassen.
    public static function setVisibility(int $id, string $newVisibility): bool
    {
        if (!in_array($newVisibility, self::VISIBILITY, true)) return false;

        $media = self::find($id);
        if (!$media) return false;

        $oldAbs = self::absolutePath($media['path']);
        if (!$oldAbs || !is_file($oldAbs)) return false;

        // Bereits am richtigen Ort (z.B. wiederholter Migrationslauf) — nur DB-Feld sicherstellen.
        $alreadyThere = ($newVisibility === 'private') === str_starts_with($media['path'], self::PRIVATE_PATH_PREFIX . '/');
        if ($alreadyThere && $media['visibility'] === $newVisibility) return true;

        $filename = basename($media['path']);
        if ($newVisibility === 'private') {
            $dir = self::privateDir();
            if (!is_dir($dir)) mkdir($dir, 0750, true);
            $newPath = self::PRIVATE_PATH_PREFIX . '/' . $filename;
        } else {
            $dir = \ESSE_ROOT . '/public/uploads';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $newPath = '/public/uploads/' . $filename;
        }
        $newAbs = self::absolutePath($newPath);

        if ($oldAbs !== $newAbs && !@rename($oldAbs, $newAbs)) return false;

        $t = DB::table('media');
        DB::update($t, ['path' => $newPath, 'visibility' => $newVisibility], ['id' => $id]);
        return true;
    }

    // Human-readable label for the "source" column — identifies which plugin/feature registered the file
    public static function sourceLabel(string $source): string
    {
        $labels = [
            'core'   => 'Core',
            'import' => 'Import',
            'media'  => 'Mediathek',
            'editor' => 'Editor',
        ];
        if (isset($labels[$source])) return $labels[$source];

        // Plugin-Slugs wie "esse-gallery" → "Gallery"
        return ucfirst(preg_replace('/^esse-/', '', $source));
    }

    public static function typeFromMime(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) return 'image';
        if ($mime === 'application/pdf' || str_contains($mime, 'document') || str_contains($mime, 'msword')) return 'document';
        return 'file';
    }

    public static function register(string $path, array $meta = []): int
    {
        $t = DB::table('media');

        $visibility = $meta['visibility'] ?? 'public';

        $data = [
            'path'        => $path,
            'filename'    => $meta['filename']    ?? basename($path),
            'mime_type'   => $meta['mime_type']   ?? '',
            'type'        => $meta['type']        ?? self::typeFromMime($meta['mime_type'] ?? ''),
            'size'        => $meta['size']        ?? 0,
            'alt_text'    => $meta['alt_text']    ?? '',
            'description' => $meta['description'] ?? '',
            'visibility'  => in_array($visibility, self::VISIBILITY, true) ? $visibility : 'public',
            'source'      => $meta['source']      ?? 'core',
            'uploaded_by' => $meta['uploaded_by']  ?? null,
            'folder_id'   => $meta['folder_id']    ?? null,
        ];

        $existing = DB::fetch("SELECT id FROM `{$t}` WHERE `path` = ?", [$path]);
        if ($existing) {
            DB::update($t, $data, ['id' => $existing['id']]);
            return (int) $existing['id'];
        }

        return DB::insert($t, $data);
    }

    public static function list(array $filters = []): array
    {
        $t = DB::table('media');
        $where  = [];
        $params = [];

        if (!empty($filters['type'])) {
            $where[] = '`type` = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['visibility'])) {
            $where[] = '`visibility` = ?';
            $params[] = $filters['visibility'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(`filename` LIKE ? OR `alt_text` LIKE ? OR `description` LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like);
        }
        if (!empty($filters['ids'])) {
            $ids = array_filter(array_map('intval', (array) $filters['ids']));
            if (!$ids) return [];
            $where[] = '`id` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        // array_key_exists statt empty(), da NULL (Root-Ebene) ein gueltiger expliziter Filterwert ist
        if (array_key_exists('folder_id', $filters)) {
            if ($filters['folder_id'] === null) {
                $where[] = '`folder_id` IS NULL';
            } else {
                $where[] = '`folder_id` = ?';
                $params[] = (int) $filters['folder_id'];
            }
        }

        $sql = "SELECT * FROM `{$t}`";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY `created_at` DESC';

        return DB::fetchAll($sql, $params);
    }

    public static function find(int $id): ?array
    {
        $t = DB::table('media');
        return DB::fetch("SELECT * FROM `{$t}` WHERE `id` = ?", [$id]);
    }

    public static function findByPath(string $path): ?array
    {
        $t = DB::table('media');
        return DB::fetch("SELECT * FROM `{$t}` WHERE `path` = ?", [$path]);
    }

    // `visibility` ist hier bewusst NICHT erlaubt — eine Sichtbarkeitsänderung muss die Datei
    // physisch verschieben (öffentlicher Webroot <-> geschützter Speicherort), das übernimmt
    // ausschließlich setVisibility().
    public static function update(int $id, array $data): void
    {
        $t = DB::table('media');
        $allowed = array_intersect_key($data, array_flip(['alt_text', 'description', 'folder_id']));
        if (!$allowed) return;
        DB::update($t, $allowed, ['id' => $id]);
    }

    public static function delete(int $id): bool
    {
        $media = self::find($id);
        if (!$media) return false;

        $t = DB::table('media');
        DB::delete($t, ['id' => $id]);

        // Versteckte Dateien (z.B. .htaccess, .gitkeep) nie vom Server loeschen,
        // nur den fehlerhaften Mediathek-Eintrag entfernen.
        $fileRemains = false;
        if (!str_starts_with(basename($media['path']), '.')) {
            $abs  = self::absolutePath($media['path']);
            // Containment-Pruefung gegen das zur Pfad-Konvention passende Basisverzeichnis —
            // verhindert, dass ein manipulierter `path`-Wert per realpath()-Aufloesung ausserhalb
            // von public/uploads bzw. dem geschuetzten Speicherort landet.
            $expectedBase = str_starts_with($media['path'], self::PRIVATE_PATH_PREFIX . '/')
                ? realpath(self::privateDir())
                : realpath(\ESSE_ROOT . '/public/uploads');
            $file = $abs ? realpath($abs) : false;
            if ($expectedBase && $file && str_starts_with($file, $expectedBase . DIRECTORY_SEPARATOR) && is_file($file)) {
                @unlink($file);
                clearstatcache(true, $file);
                $fileRemains = is_file($file);
            }
        }

        $details = ['media_id' => $id, 'path' => $media['path'], 'filename' => $media['filename']];
        AuditLog::record(
            $fileRemains ? 'media_delete_failed' : 'media_deleted',
            Auth::id(),
            Auth::user()['email'] ?? null,
            $details
        );

        return true;
    }

    /**
     * Returns titles/slugs of CMS pages whose content references the given path.
     */
    public static function usages(string $path): array
    {
        $t = DB::table('pages');
        $rows = DB::fetchAll(
            "SELECT `slug`, `title` FROM `{$t}` WHERE `content` LIKE ?",
            ['%' . $path . '%']
        );

        return $rows;
    }

    /**
     * Scans /public/uploads for files not yet present in the media index and registers them.
     */
    public static function scanUploads(): int
    {
        $dir = ESSE_ROOT . '/public/uploads';
        if (!is_dir($dir)) return 0;

        $imported = 0;
        foreach (scandir($dir) as $file) {
            if (str_starts_with($file, '.') || !is_file($dir . '/' . $file)) continue;

            $path = '/public/uploads/' . $file;
            if (self::findByPath($path)) continue;

            $mime = mime_content_type($dir . '/' . $file) ?: '';
            self::register($path, [
                'filename'  => $file,
                'mime_type' => $mime,
                'size'      => filesize($dir . '/' . $file) ?: 0,
                'source'    => 'import',
            ]);
            $imported++;
        }

        return $imported;
    }

    // ── Ordner-Verwaltung ────────────────────────────────────────────────────

    public static function listFolders(?int $parentId = null): array
    {
        $t = DB::table('media_folders');
        if ($parentId === null) {
            return DB::fetchAll("SELECT * FROM `{$t}` WHERE `parent_id` IS NULL ORDER BY `name` ASC");
        }
        return DB::fetchAll("SELECT * FROM `{$t}` WHERE `parent_id` = ? ORDER BY `name` ASC", [$parentId]);
    }

    public static function findFolder(int $id): ?array
    {
        $t = DB::table('media_folders');
        return DB::fetch("SELECT * FROM `{$t}` WHERE `id` = ?", [$id]);
    }

    public static function allFolders(): array
    {
        $t = DB::table('media_folders');
        return DB::fetchAll("SELECT * FROM `{$t}` ORDER BY `name` ASC");
    }

    public static function createFolder(string $name, ?int $parentId = null): int
    {
        $t = DB::table('media_folders');
        return DB::insert($t, ['name' => trim($name), 'parent_id' => $parentId]);
    }

    public static function renameFolder(int $id, string $name): void
    {
        $t = DB::table('media_folders');
        DB::update($t, ['name' => trim($name)], ['id' => $id]);
    }

    /**
     * Loescht einen Ordner nur, wenn er leer ist (keine Dateien, keine Unterordner).
     */
    public static function deleteFolder(int $id): bool
    {
        $tf = DB::table('media_folders');
        $tm = DB::table('media');

        if (!self::findFolder($id)) return false;

        if (DB::fetch("SELECT id FROM `{$tm}` WHERE `folder_id` = ? LIMIT 1", [$id])) return false;
        if (DB::fetch("SELECT id FROM `{$tf}` WHERE `parent_id` = ? LIMIT 1", [$id])) return false;

        DB::delete($tf, ['id' => $id]);
        return true;
    }

    /**
     * Baut den Breadcrumb-Pfad von der Wurzel bis zum gegebenen Ordner
     * (aeltester Vorfahre zuerst).
     */
    public static function folderPath(?int $folderId): array
    {
        $path = [];
        $current = $folderId;
        $guard = 0; // Schutz vor Zirkelbezuegen

        while ($current !== null && $guard < 50) {
            $folder = self::findFolder($current);
            if (!$folder) break;
            array_unshift($path, ['id' => (int) $folder['id'], 'name' => $folder['name']]);
            $current = $folder['parent_id'] !== null ? (int) $folder['parent_id'] : null;
            $guard++;
        }

        return $path;
    }
}
