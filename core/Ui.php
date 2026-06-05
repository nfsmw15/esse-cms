<?php

declare(strict_types=1);

namespace Esse;

/**
 * Theme-agnostic UI component layer.
 *
 * Plugins call Ui::panel(), Ui::button() etc. and get semantically correct,
 * theme-independent HTML with esse-* CSS classes.
 *
 * Themes can override any component via the ui.{component} Hook:
 *   Hooks::on('ui.panel', fn(string $default, array $props) => '<div>...</div>');
 *
 * Themes MUST style the esse-* classes. See THEME_GUIDE.md.
 */
class Ui
{
    // ── Panel ─────────────────────────────────────────────────────────────────

    /**
     * A content container with optional title, footer and variant.
     *
     * Options:
     *   icon    string  CSS icon class shown before title (e.g. 'bi bi-image')
     *   footer  string  Raw HTML for the panel footer
     *   variant string  'default' | 'success' | 'warning' | 'danger' | 'info'
     *   class   string  Additional CSS classes on the root element
     *   id      string  HTML id attribute
     */
    public static function panel(string $title, string $content, array $opts = []): string
    {
        $variant = $opts['variant'] ?? 'default';
        $class   = trim('esse-panel esse-panel--' . $variant . ' ' . ($opts['class'] ?? ''));
        $id      = isset($opts['id']) ? ' id="' . self::e($opts['id']) . '"' : '';
        $icon    = isset($opts['icon']) ? self::icon($opts['icon']) . ' ' : '';
        $footer  = isset($opts['footer']) ? '<div class="esse-panel-footer">' . $opts['footer'] . '</div>' : '';

        $html = '<div class="' . $class . '"' . $id . '>'
              . ($title ? '<div class="esse-panel-header">' . $icon . self::e($title) . '</div>' : '')
              . '<div class="esse-panel-body">' . $content . '</div>'
              . $footer
              . '</div>';

        return self::hook('panel', $html, compact('title', 'content', 'opts'));
    }

    // ── Button ────────────────────────────────────────────────────────────────

    /**
     * A button or link-button.
     *
     * Options:
     *   variant string  'primary' | 'secondary' | 'danger' | 'ghost' | 'link'
     *   size    string  'sm' | 'md' (default) | 'lg'
     *   icon    string  CSS icon class shown before label
     *   method  string  If set ('post'), wraps in a <form> with hidden method
     *   csrf    bool    Auto-add CSRF token when method=post (default: true)
     *   disabled bool
     *   class   string
     *   attr    array   Additional HTML attributes ['name' => 'value']
     */
    public static function button(string $label, string $url = '#', array $opts = []): string
    {
        $variant  = $opts['variant'] ?? 'primary';
        $size     = $opts['size']    ?? 'md';
        $class    = trim('esse-btn esse-btn--' . $variant . ' esse-btn--' . $size . ' ' . ($opts['class'] ?? ''));
        $disabled = !empty($opts['disabled']) ? ' disabled aria-disabled="true"' : '';
        $icon     = isset($opts['icon']) ? self::icon($opts['icon']) . ' ' : '';
        $extra    = self::attrs($opts['attr'] ?? []);
        $method   = strtolower($opts['method'] ?? 'get');

        $inner = $icon . self::e($label);

        $type = $opts['type'] ?? null;

        if ($type === 'submit') {
            // Plain submit button — use inside an existing <form>
            $html = '<button type="submit" class="' . $class . '"' . $disabled . $extra . '>' . $inner . '</button>';
        } elseif ($method === 'post') {
            $csrf = ($opts['csrf'] ?? true) && class_exists('\Esse\Auth')
                ? '<input type="hidden" name="_csrf" value="' . Auth::csrfToken() . '">'
                : '';
            $html = '<form method="post" action="' . self::e($url) . '" class="esse-btn-form">'
                  . $csrf
                  . ($opts['hidden'] ?? '')
                  . '<button type="submit" class="' . $class . '"' . $disabled . $extra . '>' . $inner . '</button>'
                  . '</form>';
        } else {
            $html = '<a href="' . self::e($url) . '" class="' . $class . '"' . $disabled . $extra . '>' . $inner . '</a>';
        }

        return self::hook('button', $html, compact('label', 'url', 'opts'));
    }

    // ── Alert ─────────────────────────────────────────────────────────────────

    /**
     * An inline notification message.
     *
     * Options:
     *   dismissible bool   Add a close button (default: false)
     *   icon        string CSS icon class
     */
    public static function alert(string $type, string $message, array $opts = []): string
    {
        $icons = [
            'success' => 'bi bi-check-circle-fill',
            'danger'  => 'bi bi-exclamation-triangle-fill',
            'warning' => 'bi bi-exclamation-circle-fill',
            'info'    => 'bi bi-info-circle-fill',
        ];
        $iconName = $opts['icon'] ?? ($icons[$type] ?? '');
        $iHtml    = $iconName ? self::icon($iconName) . ' ' : '';
        $close  = !empty($opts['dismissible'])
            ? '<button type="button" class="esse-alert-close" onclick="this.closest(\'.esse-alert\').remove()">×</button>'
            : '';

        $html = '<div class="esse-alert esse-alert--' . self::e($type) . '">'
              . $iHtml . $message . $close
              . '</div>';

        return self::hook('alert', $html, compact('type', 'message', 'opts'));
    }

    // ── Badge ─────────────────────────────────────────────────────────────────

    /**
     * A small inline status indicator.
     *
     * Types: 'default' | 'success' | 'warning' | 'danger' | 'info'
     */
    public static function badge(string $label, string $type = 'default'): string
    {
        $html = '<span class="esse-badge esse-badge--' . self::e($type) . '">' . self::e($label) . '</span>';
        return self::hook('badge', $html, compact('label', 'type'));
    }

    // ── Grid ──────────────────────────────────────────────────────────────────

    /**
     * A responsive grid of items.
     *
     * $items: array of HTML strings (each item is one cell)
     *
     * Options:
     *   cols  int   Target columns (2, 3, 4, 6) — default 4
     *   gap   string  CSS gap value — default '1rem'
     */
    public static function grid(array $items, array $opts = []): string
    {
        $cols = (int) ($opts['cols'] ?? 4);

        // $items may be plain HTML strings OR arrays with ['content'=>..., 'href'=>..., 'label'=>...]
        $cells = implode('', array_map(function($item) {
            if (is_array($item)) {
                $content = $item['content'] ?? '';
                $href    = $item['href']    ?? null;
                $label   = isset($item['label'])
                    ? '<span class="esse-grid-item-label">' . self::e($item['label']) . '</span>'
                    : '';
                if ($href) {
                    return '<a href="' . self::e($href) . '" class="esse-grid-item esse-grid-item--link">'
                         . $content . $label . '</a>';
                }
                return '<div class="esse-grid-item">' . $content . $label . '</div>';
            }
            return '<div class="esse-grid-item">' . $item . '</div>';
        }, $items));
        $html = '<div class="esse-grid-wrap">'
              . '<div class="esse-grid" data-cols="' . $cols . '">' . $cells . '</div>'
              . '</div>';

        return self::hook('grid', $html, compact('items', 'opts'));
    }

    // ── Empty State ───────────────────────────────────────────────────────────

    /**
     * Shown when a list/collection is empty.
     *
     * Options:
     *   icon   string  CSS icon class
     *   action string  Raw HTML for a call-to-action button/link
     */
    public static function emptyState(string $title, string $message = '', array $opts = []): string
    {
        $icon   = isset($opts['icon']) ? '<div class="esse-empty-icon">' . self::icon($opts['icon']) . '</div>' : '';
        $msg    = $message ? '<p class="esse-empty-message">' . self::e($message) . '</p>' : '';
        $action = isset($opts['action']) ? '<div class="esse-empty-action">' . $opts['action'] . '</div>' : '';

        $html = '<div class="esse-empty-state">'
              . $icon
              . '<h3 class="esse-empty-title">' . self::e($title) . '</h3>'
              . $msg . $action
              . '</div>';

        return self::hook('emptyState', $html, compact('title', 'message', 'opts'));
    }

    // ── Section ───────────────────────────────────────────────────────────────

    /**
     * A titled content section, less prominent than a panel.
     *
     * Options:
     *   action string  Raw HTML for a top-right action (e.g. "Add" button)
     */
    public static function section(string $title, string $content, array $opts = []): string
    {
        $action = isset($opts['action']) ? '<div class="esse-section-action">' . $opts['action'] . '</div>' : '';

        $html = '<section class="esse-section">'
              . '<div class="esse-section-header"><h2 class="esse-section-title">' . self::e($title) . '</h2>' . $action . '</div>'
              . '<div class="esse-section-body">' . $content . '</div>'
              . '</section>';

        return self::hook('section', $html, compact('title', 'content', 'opts'));
    }

    // ── Table ─────────────────────────────────────────────────────────────────

    /**
     * A data table.
     *
     * $headers: ['Column A', 'Column B', ...]
     * $rows:    [['Cell 1', 'Cell 2'], ...]  — cells may contain raw HTML
     *
     * Options:
     *   striped bool
     */
    public static function table(array $headers, array $rows, array $opts = []): string
    {
        $class = 'esse-table' . (!empty($opts['striped']) ? ' esse-table--striped' : '');

        $head = '<thead><tr>'
              . implode('', array_map(fn($h) => '<th>' . self::e($h) . '</th>', $headers))
              . '</tr></thead>';

        $body = '<tbody>';
        foreach ($rows as $row) {
            $body .= '<tr>' . implode('', array_map(fn($c) => '<td>' . $c . '</td>', $row)) . '</tr>';
        }
        $body .= '</tbody>';

        $html = '<div class="esse-table-wrap"><table class="' . $class . '">' . $head . $body . '</table></div>';

        return self::hook('table', $html, compact('headers', 'rows', 'opts'));
    }

    // ── Icon ──────────────────────────────────────────────────────────────────

    /**
     * Render an icon using the active icon pack.
     *
     * Pass only the icon name (e.g. 'house'), not the full CSS class.
     * The icon pack prefix is read from the active theme settings.
     *
     * Example: Ui::icon('house')
     *   → Bootstrap Icons: <i class="bi bi-house"></i>
     *   → Phosphor:        <i class="ph ph-house"></i>
     */
    /**
     * Options:
     *   color  string  'primary'|'success'|'warning'|'danger'|'info'|'muted'
     *   size   string  'sm'|'md'|'lg'|'xl'  (sm=0.875rem, md=1rem, lg=1.5rem, xl=2rem)
     */
    public static function icon(string $name, string $fallbackClass = '', array $opts = []): string
    {
        $prefix    = self::iconPrefix();
        $iconClass = $prefix ? $prefix . $name : ($fallbackClass ?: 'esse-icon');
        $extra     = '';
        if (!empty($opts['color'])) $extra .= ' esse-color--' . self::e($opts['color']);
        if (!empty($opts['size']))  $extra .= ' esse-size--'  . self::e($opts['size']);
        $html = '<i class="' . self::e($iconClass) . $extra . '"></i>';
        return self::hook('icon', $html, compact('name', 'opts'));
    }

    /**
     * Return the URL of the active icon pack's CSS file.
     * Themes must include this in their <head> for Ui::icon() output to render correctly.
     */
    public static function iconPackCssUrl(): string
    {
        static $url = null;
        if ($url !== null) return $url;

        $fallback = '/public/vendor/bootstrap-icons/bootstrap-icons.min.css';

        if (!class_exists('\Esse\DB') || !defined('ESSE_DB_NAME')) {
            return $url = $fallback;
        }

        try {
            $ts       = DB::table('settings');
            $packName = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'icon_pack'") ?? 'bootstrap-icons';
            $packDir  = '/public/vendor/' . preg_replace('/[^a-z0-9\-]/', '', $packName);
            $packJson = ESSE_ROOT . $packDir . '/iconpack.json';

            if (file_exists($packJson)) {
                $meta = json_decode(file_get_contents($packJson), true);
                $url  = $packDir . '/' . ($meta['css'] ?? '');
            } else {
                $url = $fallback;
            }
        } catch (\Throwable) {
            $url = $fallback;
        }

        return $url;
    }

    /**
     * Return a <link> tag for the active icon pack's CSS.
     * Drop this into <head> and Ui::icon() will render correctly regardless of which pack is active.
     */
    public static function iconPackCssTag(): string
    {
        return '<link rel="stylesheet" href="' . self::e(self::iconPackCssUrl()) . '">';
    }

    // ── Tabs ──────────────────────────────────────────────────────────────────

    /**
     * Tab navigation with content panels.
     *
     * $tabs: [['label' => 'Tab 1', 'content' => '<p>...</p>', 'active' => true], ...]
     *
     * Options:
     *   id  string  Base ID for tab elements (auto-generated if omitted)
     */
    public static function tabs(array $tabs, array $opts = []): string
    {
        $baseId = $opts['id'] ?? 'esse-tabs-' . substr(md5(serialize($tabs)), 0, 6);

        $nav = '<ul class="esse-tabs-nav" role="tablist">';
        $panels = '<div class="esse-tabs-content">';

        foreach ($tabs as $i => $tab) {
            $tabId    = $baseId . '-' . $i;
            $isActive = !empty($tab['active']) || ($i === 0 && !array_filter($tabs, fn($t) => !empty($t['active'])));
            $activeClass = $isActive ? ' esse-tabs-nav-item--active' : '';

            $nav .= '<li class="esse-tabs-nav-item' . $activeClass . '" role="presentation">'
                  . '<button class="esse-tabs-btn" data-esse-tab="' . $tabId . '" role="tab">'
                  . self::e($tab['label']) . '</button></li>';

            $panels .= '<div class="esse-tabs-panel' . ($isActive ? ' esse-tabs-panel--active' : '') . '" id="' . $tabId . '">'
                     . ($tab['content'] ?? '') . '</div>';
        }

        $nav    .= '</ul>';
        $panels .= '</div>';

        $html = '<div class="esse-tabs">' . $nav . $panels . '</div>'
              . '<script>(function(){document.querySelectorAll("[data-esse-tab]").forEach(function(btn){btn.addEventListener("click",function(){var id=this.getAttribute("data-esse-tab"),tabs=this.closest(".esse-tabs");tabs.querySelectorAll(".esse-tabs-panel").forEach(function(p){p.classList.remove("esse-tabs-panel--active")});tabs.querySelectorAll(".esse-tabs-btn").forEach(function(b){b.closest(".esse-tabs-nav-item").classList.remove("esse-tabs-nav-item--active")});document.getElementById(id).classList.add("esse-tabs-panel--active");this.closest(".esse-tabs-nav-item").classList.add("esse-tabs-nav-item--active")})})})();</script>';

        return self::hook('tabs', $html, compact('tabs', 'opts'));
    }

    // ── Divider ───────────────────────────────────────────────────────────────

    /**
     * A horizontal rule / section separator.
     *
     * Options:
     *   spacing string  'sm' | 'md' (default) | 'lg' | 'xl' | 'none'
     *   label   string  Optional centered label text on the divider
     */
    public static function divider(array $opts = []): string
    {
        $spacing = $opts['spacing'] ?? 'md';
        $class   = 'esse-divider esse-divider--' . self::e($spacing);
        $label   = $opts['label'] ?? '';

        if ($label) {
            $html = '<div class="' . $class . ' esse-divider--labeled">'
                  . '<span class="esse-divider-label">' . self::e($label) . '</span>'
                  . '</div>';
        } else {
            $html = '<hr class="' . $class . '">';
        }

        return self::hook('divider', $html, compact('opts'));
    }

    // ── Breadcrumb ────────────────────────────────────────────────────────────

    /**
     * Navigation breadcrumb trail.
     *
     * $items: [['label' => 'Home', 'url' => '/'], ['label' => 'Current']]
     * The last item without a 'url' key is rendered as the current (non-linked) page.
     */
    public static function breadcrumb(array $items): string
    {
        $parts = [];
        $last  = count($items) - 1;

        foreach ($items as $i => $item) {
            if ($i === $last || !isset($item['url'])) {
                $parts[] = '<li class="esse-breadcrumb-item esse-breadcrumb-item--current" aria-current="page">'
                         . self::e($item['label']) . '</li>';
            } else {
                $parts[] = '<li class="esse-breadcrumb-item">'
                         . '<a href="' . self::e($item['url']) . '" class="esse-breadcrumb-link">'
                         . self::e($item['label']) . '</a></li>';
            }
        }

        $html = '<nav class="esse-breadcrumb" aria-label="breadcrumb">'
              . '<ol class="esse-breadcrumb-list">' . implode('', $parts) . '</ol>'
              . '</nav>';

        return self::hook('breadcrumb', $html, compact('items'));
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private static function hook(string $component, string $defaultHtml, array $props): string
    {
        if (!class_exists('\Esse\Hooks')) return $defaultHtml;
        return Hooks::filter('ui.' . $component, $defaultHtml, $props);
    }

    private static function iconPrefix(): string
    {
        static $prefix = null;
        if ($prefix !== null) return $prefix;

        // Read from active theme settings if available
        if (class_exists('\Esse\DB') && defined('ESSE_DB_NAME')) {
            try {
                $ts    = DB::table('settings');
                $packName = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'icon_pack'") ?? 'bootstrap-icons';
                // Load prefix from installed iconpack.json
                $packDir  = ESSE_ROOT . '/public/vendor/' . preg_replace('/[^a-z0-9\-]/', '', $packName);
                $packJson = $packDir . '/iconpack.json';
                if (file_exists($packJson)) {
                    $packMeta = json_decode(file_get_contents($packJson), true);
                    $prefix   = $packMeta['prefix'] ?? 'bi bi-';
                } else {
                    $prefix = 'bi bi-'; // Bootstrap Icons as fallback
                }
            } catch (\Throwable) {
                $prefix = 'bi bi-';
            }
        } else {
            $prefix = 'bi bi-';
        }

        return $prefix;
    }

    /** Escape HTML output */
    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Build HTML attribute string from array */
    public static function attrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $key => $value) {
            if ($value === null || $value === false) continue;
            $out .= ' ' . self::e((string)$key) . '="' . self::e((string)$value) . '"';
        }
        return $out;
    }
}
