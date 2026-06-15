<?php

declare(strict_types=1);

namespace Esse;

class Seo
{
    // Add meta_description/hide_title columns to existing installations (fresh installs get them via Schema.php)
    public static function migrateDb(): void
    {
        $tp = DB::table('pages');

        $cols = DB::fetchAll("SHOW COLUMNS FROM `{$tp}`");
        if (!in_array('meta_description', array_column($cols, 'Field'), true)) {
            DB::query("ALTER TABLE `{$tp}` ADD COLUMN `meta_description` VARCHAR(300) DEFAULT NULL AFTER `content`");
        }
        if (!in_array('hide_title', array_column($cols, 'Field'), true)) {
            DB::query("ALTER TABLE `{$tp}` ADD COLUMN `hide_title` TINYINT(1) NOT NULL DEFAULT 0 AFTER `icon`");
        }
    }

    // robots.txt content — custom rules from settings, or sensible defaults
    public static function robotsTxt(array $settings): string
    {
        $custom = trim((string) ($settings['seo_robots_txt'] ?? ''));
        if ($custom !== '') {
            return $custom . "\n";
        }

        $lines = ['User-agent: *', 'Allow: /'];
        if (($settings['seo_sitemap_enabled'] ?? '0') === '1' && !empty($settings['site_url'])) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . rtrim((string) $settings['site_url'], '/') . '/sitemap.xml';
        }
        return implode("\n", $lines) . "\n";
    }

    // sitemap.xml content listing all publicly visible, published pages
    public static function sitemapXml(array $settings): string
    {
        $tp   = DB::table('pages');
        $tv   = DB::table('page_visibility');
        $base = rtrim((string) ($settings['site_url'] ?? ''), '/');

        $pages = DB::fetchAll(
            "SELECT p.slug, p.updated_at,
                    COALESCE(v.visibility, p.visibility, 'public') AS visibility
               FROM `{$tp}` p
               LEFT JOIN `{$tv}` v ON v.slug = p.slug
              WHERE p.status = 'published'"
        );

        $urls = '';
        foreach ($pages as $page) {
            if (PageVisibility::normalize((string) $page['visibility']) !== 'public') {
                continue;
            }
            $loc = $base . '/' . ltrim((string) $page['slug'], '/');
            $lastmod = date('Y-m-d', strtotime((string) $page['updated_at']));
            $urls .= "  <url>\n"
                   . '    <loc>' . htmlspecialchars($loc, ENT_XML1) . "</loc>\n"
                   . "    <lastmod>{$lastmod}</lastmod>\n"
                   . "  </url>\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
             . $urls
             . "</urlset>\n";
    }
}
