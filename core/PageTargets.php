<?php

declare(strict_types=1);

namespace Esse;

class PageTargets
{
    public static function corePages(): array
    {
        return [
            ['slug' => '/admin/login', 'title' => 'Loginseite'],
            ['slug' => '/registrieren', 'title' => 'Registrierungsseite'],
            ['slug' => '/profil', 'title' => 'Profilseite'],
        ];
    }

    public static function publishedPages(): array
    {
        $tp = DB::table('pages');
        return DB::fetchAll("SELECT slug, title FROM `{$tp}` WHERE status = 'published' ORDER BY title ASC");
    }

    public static function pluginPages(): array
    {
        return array_values(Plugin::getRegisteredPages());
    }

    public static function redirectUrl(string $target, string $fallback = '/'): string
    {
        $target = trim($target);
        if ($target === '') return $fallback;

        if (str_starts_with($target, '/') && !str_starts_with($target, '//')) {
            return $target;
        }

        return '/' . ltrim($target, '/');
    }
}
