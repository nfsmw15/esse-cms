<?php

declare(strict_types=1);

namespace Esse;

class Router
{
    private static array $routes = [];
    private static array $named  = [];

    // -- Route registration --

    public static function get(string $pattern, callable|array|string $handler, array $options = []): void
    {
        self::add('GET', $pattern, $handler, $options);
    }

    public static function post(string $pattern, callable|array|string $handler, array $options = []): void
    {
        self::add('POST', $pattern, $handler, $options);
    }

    public static function put(string $pattern, callable|array|string $handler, array $options = []): void
    {
        self::add('PUT', $pattern, $handler, $options);
    }

    public static function delete(string $pattern, callable|array|string $handler, array $options = []): void
    {
        self::add('DELETE', $pattern, $handler, $options);
    }

    private static function add(string $method, string $pattern, callable|array|string $handler, array $options): void
    {
        $route = [
            'method'  => $method,
            'pattern' => $pattern,
            'handler' => $handler,
            'auth'    => $options['auth'] ?? 'public',
            'name'    => $options['name'] ?? null,
        ];

        self::$routes[] = $route;

        if ($route['name']) {
            self::$named[$route['name']] = $pattern;
        }
    }

    // -- Dispatch --

    public static function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD']);

        // HTML forms only support GET/POST; allow _method override for PUT/DELETE
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper($_POST['_method']);
            if (in_array($override, ['PUT', 'DELETE', 'PATCH'], true)) {
                $method = $override;
            }
        }

        $uri = self::currentUri();

        foreach (self::$routes as $route) {
            if ($route['method'] !== $method) continue;

            $params = self::match($route['pattern'], $uri);
            if ($params === null) continue;

            if (!self::checkAuth($route['auth'])) {
                if (!Auth::check()) {
                    // Not logged in → send to login with redirect back
                    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
                    header('Location: /admin/login?redirect=' . $redirect);
                    exit;
                }
                self::abort(403);
                return;
            }

            self::invoke($route['handler'], $params);
            return;
        }

        self::abort(404);
    }

    // -- URL generation --

    public static function url(string $name, array $params = []): string
    {
        if (!isset(self::$named[$name])) {
            throw new \RuntimeException("No route named '{$name}'");
        }

        $url = self::$named[$name];
        foreach ($params as $key => $value) {
            $url = str_replace('{' . $key . '}', rawurlencode((string) $value), $url);
        }
        return $url;
    }

    // -- Internals --

    private static function currentUri(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        return '/' . trim($uri ?? '/', '/');
    }

    private static function match(string $pattern, string $uri): ?array
    {
        // {param} becomes a named capture group; {param?} is optional
        $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\?\}/', '(?P<$1>[^/]*)', $pattern);
        $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',  '(?P<$1>[^/]+)', $regex);
        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $uri, $matches)) {
            return null;
        }

        return array_filter($matches, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
    }

    private static function checkAuth(string|array $required): bool
    {
        if ($required === 'public') return true;
        if (is_array($required)) return Auth::canAny($required);

        // Named roles: check hierarchy
        if (in_array($required, Auth::ROLES, true)) {
            return Auth::meetsRole($required);
        }

        // Anything else is treated as a permission slug (e.g. 'php_upload')
        return Auth::can($required);
    }

    private static function invoke(callable|array|string $handler, array $params): void
    {
        if (is_callable($handler)) {
            $handler(...array_values($params));
            return;
        }

        // 'Controller@method' string
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $fqcn = str_contains($class, '\\') ? $class : 'Esse\\Controllers\\' . $class;
            (new $fqcn())->$method(...array_values($params));
            return;
        }
    }

    public static function abort(int $code): void
    {
        http_response_code($code);

        $titles = [
            403 => 'Zugriff verweigert',
            404 => 'Seite nicht gefunden',
            500 => 'Serverfehler',
        ];
        $messages = [
            403 => 'Du hast keine Berechtigung diese Seite aufzurufen.',
            404 => 'Die aufgerufene Seite existiert nicht oder wurde verschoben.',
            500 => 'Ein interner Fehler ist aufgetreten.',
        ];

        $title   = $titles[$code]   ?? (string) $code;
        $message = $messages[$code] ?? '';

        $customPage = self::customErrorPage();
        if ($customPage) {
            $customPage['error_code'] = $code;
            $customPage['error_title'] = $title;
            $customPage['error_message'] = $message;
            $customPage['custom_error_page'] = true;
            $content = Hooks::filter('page.content', $customPage['content'] ?? '', $customPage);

            if (Hooks::has('page.render')) {
                Hooks::fire('page.render', $customPage, $content);
                return;
            }

            header('Content-Type: text/html; charset=utf-8');
            echo '<div class="esse-content">' . $content . '</div>';
            return;
        }

        // Let the active theme render the error page
        if (Hooks::has('page.render')) {
            $fakePage = [
                'slug'          => '',
                'title'         => $code . ' — ' . $title,
                'content'       => '',
                'type'          => 'standard',
                'visibility'    => 'public',
                'status'        => 'published',
                'error_code'    => $code,
                'error_title'   => $title,
                'error_message' => $message,
            ];
            Hooks::fire('page.render', $fakePage, '');
            return;
        }

        // Fallback: minimal HTML without theme
        $siteUrl = defined('ESSE_URL') ? \ESSE_URL : '/';
        echo '<!DOCTYPE html><html lang="de" data-bs-theme="dark"><head>'
           . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>' . $code . ' — ' . htmlspecialchars($title) . '</title>'
           . '<link rel="stylesheet" href="/public/vendor/bootstrap/css/bootstrap.min.css">'
           . '</head><body class="d-flex align-items-center justify-content-center vh-100 bg-dark text-white">'
           . '<div class="text-center">'
           . '<h1 class="display-1 fw-bold text-secondary">' . $code . '</h1>'
           . '<h2 class="h4 mb-3">' . htmlspecialchars($title) . '</h2>'
           . '<p class="text-secondary mb-4">' . htmlspecialchars($message) . '</p>'
           . '<a href="' . htmlspecialchars($siteUrl) . '" class="btn btn-outline-light">Startseite</a>'
           . '</div></body></html>';
    }

    private static function customErrorPage(): ?array
    {
        $ts = DB::table('settings');
        $slug = (string) (DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'error_page_slug'") ?? '');
        if ($slug === '' || str_starts_with($slug, '/')) return null;

        $tp = DB::table('pages');
        $page = DB::fetch(
            "SELECT * FROM `{$tp}` WHERE slug = ? AND status = 'published' AND type = 'standard'",
            [$slug]
        );

        if (!$page) return null;

        return match ($page['visibility'] ?? 'public') {
            'public'  => $page,
            'members' => Auth::check() ? $page : null,
            'admin'   => Auth::meetsRole('admin') ? $page : null,
            default   => Auth::meetsRole((string) $page['visibility']) ? $page : null,
        };
    }
}
