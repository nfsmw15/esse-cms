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
            "SELECT i.*, p.title AS page_title
               FROM `{$ti}` i
          LEFT JOIN `{$tp}` p ON p.slug = i.page_slug
              WHERE i.menu_id = ?
           ORDER BY i.parent_id IS NOT NULL, i.sort_order ASC",
            [$menu['id']]
        );

        return self::buildTree($rows);
    }

    // Returns the resolved URL for a menu item
    public static function itemUrl(array $item): string
    {
        return match ($item['type']) {
            'page'   => '/' . ltrim($item['page_slug'] ?? '', '/'),
            'url'    => $item['url'] ?? '#',
            default  => '#',
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
