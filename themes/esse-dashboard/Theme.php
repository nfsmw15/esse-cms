<?php

declare(strict_types=1);

namespace EsseDashboard;

use Esse\DB;
use Esse\Hooks;
use Esse\Menu;

class Theme extends \Esse\Theme
{
    private array $settings = [];

    public function boot(): void
    {
        $ts = DB::table('settings');
        $rows = DB::fetchAll("SELECT `key`, `value` FROM `{$ts}`");
        $this->settings = array_column($rows, 'value', 'key');

        Hooks::on('page.render', [$this, 'renderPage']);
    }

    public function renderPage(array $page, string $content): void
    {
        $siteName    = $this->settings['site_name'] ?? 'ESSE CMS';
        $sidebarSlug = $this->settings['theme_esse-dashboard_menu_sidebar'] ?? 'sidebar';
        $footSlug    = $this->settings['theme_esse-dashboard_menu_footer']  ?? 'footer';
        $footMenu    = $footSlug ? Menu::get($footSlug) : [];
        $theme       = $this;

        if (!empty($page['error_code'])) {
            require $this->basePath('templates/error.php');
            return;
        }

        // Not logged in
        if (!\Esse\Auth::check()) {
            // Check if this page is explicitly listed as public in theme settings
            $publicSlugs = array_map('trim', explode(',',
                $this->settings['theme_esse-dashboard_public_slugs'] ?? ''
            ));
            $currentSlug = $page['slug'] ?? '';

            if ($currentSlug && in_array($currentSlug, $publicSlugs, true)) {
                require $this->basePath('templates/public.php');
                return;
            }

            // Everything else → login page
            require $this->basePath('templates/login.php');
            return;
        }

        $sidebarMenu = Menu::get($sidebarSlug);
        require $this->basePath('templates/layout.php');
    }
}
