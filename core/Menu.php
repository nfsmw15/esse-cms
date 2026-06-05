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
            'url'    => $item['url'] ?? '#',
            default  => '#',
        };
    }

    private static function isVisible(array $item): bool
    {
        // Non-page items (URL, header) are always shown
        if ($item['type'] !== 'page') return true;

        $slug = $item['page_slug'] ?? '';

        // Plugin-registered pages are not in the DB — always show
        if (Plugin::isPluginSlug($slug)) return true;

        // Core pages like /admin/login or /registrieren are not stored in the pages table
        if (str_starts_with((string) $slug, '/')) return true;

        // Page not found in DB → show anyway (clicking may 404, admin's responsibility)
        if (empty($item['page_visibility'])) return true;

        // Draft pages → hide from menu
        if (($item['page_status'] ?? '') !== 'published') return false;

        // Check visibility for published pages
        return match ($item['page_visibility']) {
            'public'  => true,
            'members' => Auth::check(),
            'admin'   => Auth::meetsRole('admin'),
            default   => Auth::meetsRole($item['page_visibility']),
        };
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
