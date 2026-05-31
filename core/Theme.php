<?php

declare(strict_types=1);

namespace Esse;

abstract class Theme
{
    protected array $meta = [];

    final public function __construct()
    {
        $jsonFile = $this->basePath() . '/theme.json';
        if (file_exists($jsonFile)) {
            $this->meta = json_decode(file_get_contents($jsonFile), true) ?? [];
        }
    }

    // Called once when the theme is activated
    public function boot(): void {}

    // Render a template file from this theme's templates/ directory.
    // $data is extracted into the template's local scope.
    public function render(string $template, array $data = []): void
    {
        $file = $this->basePath('templates/' . ltrim($template, '/'));

        if (!file_exists($file)) {
            throw new \RuntimeException("Theme template not found: {$template}");
        }

        extract($data, EXTR_SKIP);
        require $file;
    }

    // Render and return output as a string
    public function capture(string $template, array $data = []): string
    {
        ob_start();
        $this->render($template, $data);
        return ob_get_clean() ?: '';
    }

    // -- Metadata helpers --

    public function name(): string        { return $this->meta['name']        ?? ''; }
    public function version(): string     { return $this->meta['version']     ?? '0.0.0'; }
    public function description(): string { return $this->meta['description'] ?? ''; }
    public function author(): string      { return $this->meta['author']      ?? ''; }

    // Absolute path to this theme's directory
    public function basePath(string $append = ''): string
    {
        $dir = dirname((new \ReflectionClass(static::class))->getFileName());
        return $append ? $dir . '/' . ltrim($append, '/') : $dir;
    }

    // URL to this theme's public assets (themes/<name>/assets/)
    public function assetUrl(string $path = ''): string
    {
        $name = $this->meta['name'] ?? basename($this->basePath());
        $base = rtrim(defined('ESSE_URL') ? ESSE_URL : '', '/');
        return $base . '/themes/' . $name . '/assets/' . ltrim($path, '/');
    }
}
