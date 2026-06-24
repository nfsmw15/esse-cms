<?php

declare(strict_types=1);

namespace Esse;

/**
 * Registers core (non-theme, non-plugin) built-in shortcodes/widgets.
 * Called once from index.php during bootstrap.
 */
class CoreShortcodes
{
    public static function boot(): void
    {
        Shortcodes::register('carousel', [self::class, 'renderCarousel'], [
            'label'       => 'Bildergalerie (Carousel)',
            'description' => 'Zeigt ausgewählte Bilder aus der Mediathek als Slideshow an.',
            'icon'        => 'images',
            'attributes'  => [
                ['name' => 'images',   'label' => 'Bilder',                   'type' => 'images', 'default' => ''],
                ['name' => 'interval', 'label' => 'Intervall (Sek., 0 = aus)', 'type' => 'number', 'default' => 5],
                ['name' => 'height',   'label' => 'Höhe',                     'type' => 'select',  'default' => 'md', 'options' => [
                    ['value' => 'sm',   'label' => 'Klein (220px)'],
                    ['value' => 'md',   'label' => 'Mittel (400px)'],
                    ['value' => 'lg',   'label' => 'Groß (560px)'],
                    ['value' => 'full', 'label' => 'Volle Breite (16:9)'],
                ]],
            ],
        ]);
    }

    public static function renderCarousel(array $attrs): string
    {
        $ids = array_filter(array_map('intval', explode(',', $attrs['images'] ?? '')));
        if (!$ids) return '';

        // Einmal pro Seitenaufruf prüfen statt pro Bild — dieselbe Berechtigung, die auch
        // /admin/media/file/{id} (der Ausliefer-Endpoint für private Dateien) verlangt. So
        // entscheidet sich schon beim Rendern, ob ein privates Bild ueberhaupt als <img>-Tag
        // auftaucht: berechtigte Besucher sehen es normal, alle anderen bekommen kein kaputtes
        // Bild-Icon, weil das Bild für sie komplett aus der Slide-Liste ausgelassen wird.
        $canViewPrivate = Auth::canAny(['manage_files', 'manage_content']);

        $slides = [];
        foreach ($ids as $id) {
            $media = Media::find($id);
            if (!$media || ($media['type'] ?? '') !== 'image') continue;
            $isPrivate = $media['visibility'] === 'private';
            if ($isPrivate && !$canViewPrivate) continue;
            // $media['path'] ist für private Dateien kein echter Web-Pfad (Datei liegt außerhalb
            // des Webroots) — auch für berechtigte Besucher muss der Ausliefer-Endpoint genutzt
            // werden, nicht der interne Pfad.
            $src = $isPrivate ? '/admin/media/file/' . $media['id'] : $media['path'];
            $slides[] = ['image' => $src, 'alt' => $media['alt_text'] ?? ''];
        }
        if (!$slides) return '';

        $intervalSec     = max(0, (int) ($attrs['interval'] ?? 5));
        $requestedHeight = $attrs['height'] ?? 'md';
        $height          = in_array($requestedHeight, ['sm', 'md', 'lg', 'full'], true) ? $requestedHeight : 'md';

        return Ui::carousel($slides, [
            'interval' => $intervalSec > 0 ? $intervalSec * 1000 : 0,
            'height'   => $height,
        ]);
    }
}
