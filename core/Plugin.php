<?php

declare(strict_types=1);

namespace Esse;

abstract class Plugin
{
    protected array $meta = [];

    final public function __construct()
    {
        $jsonFile = $this->basePath() . '/plugin.json';
        if (file_exists($jsonFile)) {
            $this->meta = json_decode(file_get_contents($jsonFile), true) ?? [];
        }
    }

    // Called once when the plugin is loaded on every request
    abstract public function boot(): void;

    // Called once when the plugin is installed via the plugin manager
    public function install(): void {}

    // Called once when the plugin is uninstalled
    public function uninstall(): void {}

    // -- Metadata helpers --

    public function name(): string        { return $this->meta['name']        ?? ''; }
    public function version(): string     { return $this->meta['version']     ?? '0.0.0'; }
    public function description(): string { return $this->meta['description'] ?? ''; }
    public function author(): string      { return $this->meta['author']      ?? ''; }

    // Absolute path to this plugin's directory
    public function basePath(string $append = ''): string
    {
        $dir = dirname((new \ReflectionClass(static::class))->getFileName());
        return $append ? $dir . '/' . ltrim($append, '/') : $dir;
    }

    // URL to this plugin's public assets (plugins/<name>/public/)
    public function assetUrl(string $path = ''): string
    {
        $name = $this->meta['name'] ?? basename($this->basePath());
        $base = rtrim(defined('ESSE_URL') ? \ESSE_URL : '', '/');
        return $base . '/plugins/' . $name . '/public/' . ltrim($path, '/');
    }

    // Register a link in the admin sidebar under "Plugins"
    final protected function addAdminNav(
        string $label,
        string $url,
        string $icon      = 'bi-puzzle',
        string $activeSlug = ''
    ): void {
        Hooks::on('admin.nav', function (array &$items) use ($label, $url, $icon, $activeSlug) {
            $items[] = [
                'label'  => $label,
                'url'    => $url,
                'icon'   => $icon,
                'active' => $activeSlug ?: ltrim($url, '/'),
            ];
        });
    }

    // Convenience: register a hook from within the plugin
    final protected function on(string $event, callable $callback, int $priority = 10): void
    {
        Hooks::on($event, $callback, $priority);
    }

    // Convenience: register a route from within the plugin
    final protected function route(string $method, string $pattern, callable|string $handler, array $options = []): void
    {
        Router::$method($pattern, $handler, $options);
    }
}
