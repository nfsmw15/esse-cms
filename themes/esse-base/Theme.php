<?php

declare(strict_types=1);

namespace EsseBase;

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
        $siteName = $this->settings['site_name'] ?? 'ESSE CMS';
        $mainMenu = Menu::get('main');
        $footMenu = Menu::get('footer');
        $theme    = $this;

        require $this->basePath('templates/layout.php');
    }
}
