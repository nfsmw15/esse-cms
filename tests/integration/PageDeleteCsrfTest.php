<?php

declare(strict_types=1);

use Esse\DB;

// Legt eine Test-Seite an und gibt deren Slug zurueck.
function makeTestPage(string $slug): void
{
    $tp = DB::table('pages');
    DB::delete($tp, ['slug' => $slug]);
    DB::insert($tp, [
        'slug'       => $slug,
        'title'      => 'Loesch-Testseite',
        'content'    => '<p>Test</p>',
        'type'       => 'standard',
        'visibility' => 'public',
        'status'     => 'published',
    ]);
}

function pageExists(string $slug): bool
{
    $tp = DB::table('pages');
    return DB::fetch("SELECT id FROM `{$tp}` WHERE slug = ?", [$slug]) !== null;
}

return [
    'POST /admin/pages/delete/{slug}: Member ohne manage_content erhaelt 403' => function (Http $http) {
        makeTestPage('loesch-test-member');
        loginAs($http, TEST_MEMBER_EMAIL, TEST_MEMBER_PASSWORD);

        $res = $http->post('/admin/pages/delete/loesch-test-member', []);

        Assert::same(403, $res['status']);
        Assert::true(pageExists('loesch-test-member'), 'Seite sollte nicht geloescht worden sein');

        DB::delete(DB::table('pages'), ['slug' => 'loesch-test-member']);
    },

    'POST /admin/pages/delete/{slug}: ohne CSRF-Token wird abgelehnt' => function (Http $http) {
        makeTestPage('loesch-test-csrf');
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);

        $res = $http->post('/admin/pages/delete/loesch-test-csrf', []);

        Assert::same(403, $res['status']);
        Assert::true(pageExists('loesch-test-csrf'), 'Seite sollte ohne CSRF-Token nicht geloescht werden');

        DB::delete(DB::table('pages'), ['slug' => 'loesch-test-csrf']);
    },

    'POST /admin/pages/delete/{slug}: mit gueltigem CSRF-Token wird die Seite geloescht' => function (Http $http) {
        makeTestPage('loesch-test-ok');
        loginAs($http, TEST_FORGE_EMAIL, TEST_FORGE_PASSWORD);

        $page = $http->get('/admin/pages');
        $csrf = extractCsrf($page['body']);

        $res = $http->post('/admin/pages/delete/loesch-test-ok', ['_csrf' => $csrf]);

        Assert::same(302, $res['status']);
        Assert::same('/admin/pages', $res['headers']['location'][0] ?? null);
        Assert::false(pageExists('loesch-test-ok'), 'Seite sollte geloescht worden sein');
    },
];
