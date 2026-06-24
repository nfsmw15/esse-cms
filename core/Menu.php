<?php

declare(strict_types=1);

namespace Esse;

class Menu
{
    // Returns a structured menu tree by slug.
    // Top-level items with their children nested under 'children' key.
    public static function get(string $slug): array
    {
        $tm = DB::table('menus');
        $ti = DB::table('menu_items');
        $tp = DB::table('pages');

        $menu = DB::fetch("SELECT * FROM `{$tm}` WHERE slug = ?", [$slug]);
        if (!$menu) return [];

        $rows = DB::fetchAll(
            "SELECT i.*, p.title AS page_title, p.visibility AS page_visibility, p.status AS page_status
               FROM `{$ti}` i
          LEFT JOIN `{$tp}` p ON p.slug = i.page_slug
              WHERE i.menu_id = ?
           ORDER BY i.parent_id IS NOT NULL, i.sort_order ASC",
            [$menu['id']]
        );

        // Filter out inactive and invisible items
        $rows = array_values(array_filter($rows, function($item) {
            if (empty($item['active'])) return false; // disabled by admin
            return self::isVisible($item);
        }));

        return self::buildTree($rows);
    }

    // Returns the resolved URL for a menu item
    public static function itemUrl(array $item): string
    {
        return match ($item['type']) {
            'page'   => PageTargets::redirectUrl((string) ($item['page_slug'] ?? ''), '#'),
            // Verteidigung in der Tiefe: die eigentliche Prüfung läuft bereits beim Speichern
            // (admin/menus/form.php), hier zusätzlich abgesichert für Altdaten/andere Schreibwege.
            'url'    => self::isAllowedUrl((string) ($item['url'] ?? '')) ? ($item['url'] ?: '#') : '#',
            default  => '#',
        };
    }

    // Externe Menülinks dürfen nur auf harmlose Schemes zeigen — sonst könnte z.B.
    // "javascript:..." als Linkziel gespeichert und ausgeliefert werden. CSP entschärft das
    // großteils, serverseitige Validierung ist aber die eigentliche Absicherung.
    public static function isAllowedUrl(string $url): bool
    {
        if ($url === '') return true;
        // Explizites Scheme am Anfang (z.B. "javascript:", "https:", "mailto:")?
        if (preg_match('#^([a-zA-Z][a-zA-Z0-9+.\-]*):#', $url, $m)) {
            return in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true);
        }
        // Kein Scheme -> relativer Pfad, Anker oder protokoll-relative URL (//host/pfad) -> erlaubt.
        return true;
    }

    private static function isVisible(array $item): bool
    {
        // Non-page items (URL, header) are always shown
        if ($item['type'] !== 'page') return true;

        $slug = ltrim((string) ($item['page_slug'] ?? ''), '/');
        if (!$slug) return true;

        // Draft CMS pages → hide from menu
        if (!empty($item['page_status']) && $item['page_status'] !== 'published') return false;

        // Plugin page: override table takes precedence over plugin-registered default
        if (Plugin::isPluginSlug($slug)) {
            $pluginDefault = Plugin::getRegisteredPages()[$slug]['visibility'] ?? 'public';
            $vis = PageVisibility::forPage($slug, PageVisibility::normalize($pluginDefault ?: 'public'));
            return PageVisibility::check($slug, $vis);
        }

        // Standard pages not in esse_pages (e.g. /login, /registrieren stored as bare slug)
        if (empty($item['page_visibility'])) {
            $vis = PageVisibility::forPage($slug, 'public');
            return PageVisibility::check($slug, $vis);
        }

        // CMS page — visibility stored in esse_pages
        $vis = PageVisibility::forCmsPage(['visibility' => $item['page_visibility']]);
        return PageVisibility::check($slug, $vis);
    }

    private static function buildTree(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $row['children'] = [];
            $indexed[$row['id']] = $row;
        }

        $tree = [];
        foreach ($indexed as $id => $item) {
            if ($item['parent_id'] && isset($indexed[$item['parent_id']])) {
                $indexed[$item['parent_id']]['children'][] = &$indexed[$id];
            } else {
                $tree[] = &$indexed[$id];
            }
        }

        return $tree;
    }
}
