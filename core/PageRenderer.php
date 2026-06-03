<?php

declare(strict_types=1);

namespace Esse;

class PageRenderer
{
    public static function render(string $slug): void
    {
        $t    = DB::table('pages');
        $page = DB::fetch(
            "SELECT * FROM `{$t}` WHERE slug = ? AND status = 'published'",
            [$slug]
        );

        if (!$page) {
            Router::abort(404);
            return;
        }

        // Visibility check
        $vis = $page['visibility'];
        if ($vis === 'members' && !Auth::check()) {
            header('Location: /admin/login');
            exit;
        }
        if ($vis === 'admin' && !Auth::meetsRole('admin')) {
            Router::abort(403);
            return;
        }

        if ($page['type'] === 'php') {
            self::renderPhp($page);
        } else {
            self::renderStandard($page);
        }
    }

    private static function renderStandard(array $page): void
    {
        // Hooks allow themes to wrap the output later
        $content = Hooks::filter('page.content', $page['content'], $page);

        if (Hooks::has('page.render')) {
            Hooks::fire('page.render', $page, $content);
            return;
        }

        // No theme active — minimal output
        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="esse-content">' . $content . '</div>';
    }

    // Render a PHP file (not from DB) wrapped in the active theme
    public static function renderFile(string $file, string $title = '', string $visibility = 'public'): void
    {
        if (!file_exists($file)) {
            Router::abort(404);
            return;
        }

        $fakePage = [
            'slug'       => ltrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/', '/'),
            'title'      => $title,
            'content'    => '',
            'type'       => 'standard',
            'visibility' => $visibility,
            'status'     => 'published',
        ];

        $esse_page = $fakePage;
        $esse_user = Auth::user();

        ob_start();
        require $file;
        $content = ob_get_clean();

        if (Hooks::has('page.render')) {
            Hooks::fire('page.render', $fakePage, $content);
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="esse-content">' . $content . '</div>';
    }

    private static function renderPhp(array $page): void
    {
        if (!$page['file_path']) {
            Router::abort(404);
            return;
        }

        $fileName = basename((string) $page['file_path']);
        if ($fileName !== $page['file_path']) {
            Router::abort(404);
            return;
        }

        $base = realpath(\ESSE_ROOT . '/pages');
        $file = realpath(\ESSE_ROOT . '/pages/' . $fileName);

        if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            Router::abort(404);
            return;
        }

        // Make $page and $esse_* available inside the included file
        $esse_page = $page;
        $esse_user = Auth::user();

        if (Hooks::has('page.render')) {
            ob_start();
            require $file;
            $content = ob_get_clean();
            Hooks::fire('page.render', $page, $content);
            return;
        }

        // No theme — include directly
        require $file;
    }
}
