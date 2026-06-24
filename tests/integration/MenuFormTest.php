<?php

declare(strict_types=1);

use Esse\DB;

function makeTestMenu(string $slug): int
{
    $tm = DB::table('menus');
    DB::delete($tm, ['slug' => $slug]);
    return DB::insert($tm, ['name' => $slug, 'slug' => $slug]);
}

return [
    'POST /admin/menus/edit/{id} (_action=add_item): javascript:-URL wird abgelehnt' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $menuId = makeTestMenu('test-menu-xss-url');
        $tm = DB::table('menus');
        $ti = DB::table('menu_items');

        try {
            $csrf = extractCsrf($http->get("/admin/menus/edit/{$menuId}")['body']);
            $http->post("/admin/menus/edit/{$menuId}", [
                '_csrf' => $csrf, '_action' => 'add_item',
                'type' => 'url', 'label' => 'Evil', 'url' => 'javascript:alert(1)',
            ]);

            $item = DB::fetch("SELECT * FROM `{$ti}` WHERE menu_id = ? AND label = 'Evil'", [$menuId]);
            Assert::true($item === null, 'Menüpunkt mit javascript:-URL darf nicht angelegt worden sein');
        } finally {
            DB::delete($ti, ['menu_id' => $menuId]);
            DB::delete($tm, ['id' => $menuId]);
        }
    },

    'POST /admin/menus/edit/{id} (_action=add_item): https-URL wird akzeptiert' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $menuId = makeTestMenu('test-menu-ok-url');
        $tm = DB::table('menus');
        $ti = DB::table('menu_items');

        try {
            $csrf = extractCsrf($http->get("/admin/menus/edit/{$menuId}")['body']);
            $http->post("/admin/menus/edit/{$menuId}", [
                '_csrf' => $csrf, '_action' => 'add_item',
                'type' => 'url', 'label' => 'Good', 'url' => 'https://example.com',
            ]);

            $item = DB::fetch("SELECT * FROM `{$ti}` WHERE menu_id = ? AND label = 'Good'", [$menuId]);
            Assert::true($item !== null, 'Menüpunkt mit https-URL sollte angelegt worden sein');
            Assert::same('https://example.com', $item['url']);
        } finally {
            DB::delete($ti, ['menu_id' => $menuId]);
            DB::delete($tm, ['id' => $menuId]);
        }
    },

    'POST /admin/menus/edit/{id} (_action=add_item): Icon-Feld wird auf [a-z0-9-] reduziert' => function (Http $http) {
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);
        $menuId = makeTestMenu('test-menu-icon');
        $tm = DB::table('menus');
        $ti = DB::table('menu_items');

        try {
            $csrf = extractCsrf($http->get("/admin/menus/edit/{$menuId}")['body']);
            $http->post("/admin/menus/edit/{$menuId}", [
                '_csrf' => $csrf, '_action' => 'add_item',
                'type' => 'page', 'label' => 'IconTest', 'icon' => 'house"><img src=x onerror=alert(1)>',
            ]);

            $item = DB::fetch("SELECT * FROM `{$ti}` WHERE menu_id = ? AND label = 'IconTest'", [$menuId]);
            Assert::true($item !== null);
            Assert::same('houseimgsrcxonerroralert1', $item['icon']);
        } finally {
            DB::delete($ti, ['menu_id' => $menuId]);
            DB::delete($tm, ['id' => $menuId]);
        }
    },
];
