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
        $siteName   = $this->settings['site_name'] ?? 'ESSE CMS';
        $sidebarSlug = $this->settings['theme_esse-dashboard_menu_sidebar'] ?? 'sidebar';
        $footSlug    = $this->settings['theme_esse-dashboard_menu_footer']  ?? 'footer';

        $sidebarMenu = Menu::get($sidebarSlug);
        $footMenu    = $footSlug ? Menu::get($footSlug) : [];
        $theme       = $this;

        if (!empty($page['error_code'])) {
            require $this->basePath('templates/error.php');
            return;
        }

        require $this->basePath('templates/layout.php');
    }
}
