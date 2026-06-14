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

    public static function migrateDb(): void
    {
        $p = defined('ESSE_DB_PREFIX') ? \ESSE_DB_PREFIX : 'esse_';
        foreach (Schema::tables($p) as $sql) {
            if (str_contains($sql, '`' . $p . 'media`')) {
                DB::query($sql);
            }
        }
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

    public static function update(int $id, array $data): void
    {
        $t = DB::table('media');
        $allowed = array_intersect_key($data, array_flip(['alt_text', 'description', 'visibility']));
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
        if (!str_starts_with(basename($media['path']), '.')) {
            $file = ESSE_ROOT . '/public' . $media['path'];
            if (is_file($file)) {
                @unlink($file);
            }
        }

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
}
