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
        Hooks::on('auth.login.render', [$this, 'renderLogin']);
        Hooks::on('auth.forgot_password.render', [$this, 'renderForgotPassword']);
        Hooks::on('auth.reset_password.render', [$this, 'renderResetPassword']);
    }

    public function renderLogin(array $data): void
    {
        $content = $this->renderPartial('templates/login.php', ['data' => $data]);
        $this->renderPage([
            'slug'    => 'login',
            'title'   => 'Anmelden',
            'icon'    => 'box-arrow-in-right',
            'content' => $content,
        ], $content);
    }

    public function renderForgotPassword(array $data): void
    {
        $content = $this->renderPartial('templates/forgot-password.php', ['data' => $data]);
        $this->renderPage([
            'slug'    => 'passwort-vergessen',
            'title'   => 'Passwort vergessen',
            'icon'    => 'key',
            'content' => $content,
        ], $content);
    }

    public function renderResetPassword(array $data): void
    {
        $content = $this->renderPartial('templates/reset-password.php', ['data' => $data]);
        $this->renderPage([
            'slug'    => 'neues-passwort',
            'title'   => 'Neues Passwort',
            'icon'    => 'shield-lock',
            'content' => $content,
        ], $content);
    }

    private function renderPartial(string $template, array $vars = []): string
    {
        extract($vars, EXTR_SKIP);
        $theme = $this;

        ob_start();
        require $this->basePath($template);
        return (string) ob_get_clean();
    }

    public function renderPage(array $page, string $content): void
    {
        $siteName = $this->settings['site_name'] ?? 'ESSE CMS';

        $mainSlug = $this->settings['theme_esse-base_menu_main']  ?? 'main';
        $footSlug = $this->settings['theme_esse-base_menu_footer'] ?? 'footer';

        $mainMenu = Menu::get($mainSlug);
        $footMenu = $footSlug ? Menu::get($footSlug) : [];
        $settings = $this->settings;
        $theme    = $this;

        // Error pages use a dedicated template
        if (!empty($page['error_code']) && empty($page['custom_error_page'])) {
            require $this->basePath('templates/error.php');
            return;
        }

        require $this->basePath('templates/layout.php');
    }
}
