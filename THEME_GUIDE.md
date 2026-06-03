# ESSE CMS — Theme-Entwicklung

## Grundstruktur

```
themes/mein-theme/
├── theme.json          ← Pflicht: Metadaten
├── Theme.php           ← Pflicht: PHP-Klasse
├── templates/
│   ├── layout.php      ← Pflicht: Haupt-Layout
│   ├── error.php       ← Empfohlen: 404/403-Seite
│   └── login.php       ← Optional: für Dashboard-Themes
├── assets/
│   ├── css/
│   │   └── mein-theme.css
│   └── fonts/          ← Optional
└── README.md
```

---

## theme.json

```json
{
    "name": "mein-theme",
    "version": "1.0.0",
    "description": "Kurzbeschreibung.",
    "author": "Dein Name",
    "class": "MeinTheme\\Theme",
    "menus": {
        "main":   "Hauptnavigation",
        "footer": "Footer-Links",
        "sidebar": "Sidebar (optional)"
    }
}
```

**Felder:**

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `name` | ✓ | Entspricht dem Verzeichnisnamen |
| `version` | ✓ | Semantic Versioning (`1.0.0`) |
| `description` | | Kurzbeschreibung |
| `author` | | Autor |
| `class` | ✓ | Vollständiger Klassenname inkl. Namespace |
| `menus` | | Deklarierte Menüpositionen (Schlüssel = Setting-Suffix, Wert = Label) |

---

## Theme.php

```php
<?php

declare(strict_types=1);

namespace MeinTheme;

use Esse\DB;
use Esse\Hooks;
use Esse\Menu;

class Theme extends \Esse\Theme
{
    private array $settings = [];

    public function boot(): void
    {
        // Einmalig beim Request-Start aufgerufen
        $ts = DB::table('settings');
        $rows = DB::fetchAll("SELECT `key`, `value` FROM `{$ts}`");
        $this->settings = array_column($rows, 'value', 'key');

        Hooks::on('page.render', [$this, 'renderPage']);
    }

    public function renderPage(array $page, string $content): void
    {
        $siteName = $this->settings['site_name'] ?? 'ESSE CMS';

        // Menüpositionen aus Settings laden (konfiguriert in Admin → Themes)
        $mainSlug = $this->settings['theme_mein-theme_menu_main']   ?? 'main';
        $footSlug = $this->settings['theme_mein-theme_menu_footer']  ?? 'footer';

        $mainMenu = Menu::get($mainSlug);
        $footMenu = $footSlug ? Menu::get($footSlug) : [];
        $theme    = $this;

        // Fehlerseiten abfangen
        if (!empty($page['error_code'])) {
            require $this->basePath('templates/error.php');
            return;
        }

        require $this->basePath('templates/layout.php');
    }
}
```

---

## Template-Variablen

Die folgenden Variablen stehen in `layout.php`, `error.php` und `login.php` zur Verfügung:

### Immer verfügbar

| Variable | Typ | Inhalt |
|---|---|---|
| `$page` | `array` | Aktuelle Seite (siehe unten) |
| `$content` | `string` | Gerenderter Seiteninhalt (HTML) |
| `$siteName` | `string` | Seitenname aus Einstellungen |
| `$theme` | `Theme` | Das Theme-Objekt selbst |

### $page — Felder

| Schlüssel | Typ | Inhalt |
|---|---|---|
| `slug` | `string` | URL-Slug der Seite |
| `title` | `string` | Seitentitel |
| `icon` | `string\|null` | CSS-Klasse für Icon (z.B. `bi bi-house`) |
| `visibility` | `string` | `public`, `members`, `admin` |
| `status` | `string` | `published`, `draft` |
| `type` | `string` | `standard`, `php` |
| `error_code` | `int\|null` | Gesetzt bei Fehlerseiten (404, 403) |
| `error_title` | `string\|null` | Fehler-Titel |
| `error_message` | `string\|null` | Fehler-Beschreibung |

### Menüs (selbst laden in boot())

```php
$mainMenu = Menu::get($slug);  // gibt Array mit Items zurück

// Item-Felder:
$item['label']      // Anzeigetext
$item['type']       // 'page', 'url', 'header'
$item['icon']       // CSS-Klasse (optional)
$item['target']     // '_self' oder '_blank'
$item['children']   // Array mit Untereinträgen
$item['active']     // true wenn aktuelle Seite

// URL auflösen:
\Esse\Menu::itemUrl($item)  // gibt '/slug' oder 'https://...' zurück
```

---

## Verfügbare Helfer in Templates

```php
// Auth
\Esse\Auth::check()              // bool: eingeloggt?
\Esse\Auth::user()               // array: User-Daten
\Esse\Auth::user()['display_name']
\Esse\Auth::role()               // 'forge', 'admin', 'editor', 'author', 'member'
\Esse\Auth::meetsRole('author')  // bool
\Esse\Auth::csrfToken()          // CSRF-Token für Formulare

// Theme-eigene Assets
$theme->assetUrl('css/style.css')
// → https://example.com/themes/mein-theme/assets/css/style.css
```

---

## esse-grid — Pflicht-Implementierung

Jedes Theme **muss** die esse-grid-Klassen implementieren damit Plugins theme-agnostisch funktionieren.

```css
/* Minimale Pflicht-Implementierung */
.esse-grid-wrap { width: 100%; }
.esse-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
.esse-grid[data-cols="2"] { grid-template-columns: repeat(2, 1fr); }
.esse-grid[data-cols="3"] { grid-template-columns: repeat(3, 1fr); }
.esse-grid[data-cols="4"] { grid-template-columns: repeat(4, 1fr); }
.esse-grid[data-cols="6"] { grid-template-columns: repeat(6, 1fr); }
.esse-grid-item { min-width: 0; }

/* Responsive (empfohlen) */
@media (max-width: 768px) {
  .esse-grid[data-cols="3"],
  .esse-grid[data-cols="4"] { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
  .esse-grid { grid-template-columns: repeat(2, 1fr) !important; }
}
```

| Klasse | Bedeutung |
|---|---|
| `esse-grid-wrap` | Äußerer Container |
| `esse-grid` | Grid-Container |
| `esse-grid[data-cols="N"]` | Spaltenanzahl (2, 3, 4, 6) |
| `esse-grid-item` | Einzelnes Element |

---

## Fehlerseiten-Template (templates/error.php)

```php
<?php
/**
 * @var array       $page      enthält error_code, error_title, error_message
 * @var string      $siteName
 * @var YourTheme   $theme
 */
$code    = (int) ($page['error_code']   ?? 404);
$title   = $page['error_title']         ?? 'Fehler';
$message = $page['error_message']       ?? '';
?>
<!DOCTYPE html>...
```

---

## Login-Template für geschlossene Themes (templates/login.php)

Für Dashboard-Themes die einen Login-Screen statt des normalen Layouts zeigen:

```php
<?php
/**
 * @var string      $siteName
 * @var array       $footMenu
 * @var YourTheme   $theme
 */
$redirect = $_SERVER['REQUEST_URI'] ?? '/';
?>
<!DOCTYPE html>
<html lang="de">
...
<form method="post" action="/admin/login">
    <input type="hidden" name="_csrf"    value="<?= \Esse\Auth::csrfToken() ?>">
    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
    <input type="email"    name="login"    autocomplete="username" required>
    <input type="password" name="password" autocomplete="current-password" required>
    <button type="submit">Anmelden</button>
</form>
```

In `Theme.php` aktivieren:

```php
public function renderPage(array $page, string $content): void
{
    if (!\Esse\Auth::check()) {
        // Öffentliche Seiten normal rendern, den Rest → Login
        if (($page['visibility'] ?? '') === 'public' && empty($page['error_code'])) {
            require $this->basePath('templates/public.php');
        } else {
            require $this->basePath('templates/login.php');
        }
        return;
    }
    // ...
}
```

---

## Theme-Assets ausliefern

Assets in `themes/mein-theme/assets/` sind per HTTP zugänglich (kein .htaccess-Block).

```php
// URL zu einem Asset:
$theme->assetUrl('css/style.css')
// → https://example.com/themes/mein-theme/assets/css/style.css
```

---

## Theme veröffentlichen (GitHub-Repo)

**1. GitHub-Repo anlegen + Topic setzen:**
```bash
gh repo create nfsmw15/esse-mein-theme --public
gh repo edit nfsmw15/esse-mein-theme --add-topic esse-theme
```

Das Topic `esse-theme` macht das Theme im **Admin → Themes → Verfügbar** sichtbar.

**2. Release erstellen:**
```bash
gh release create v1.0.0 \
  --repo nfsmw15/esse-mein-theme \
  --title "esse-mein-theme v1.0.0" \
  --notes "Initial release"
```

GitHub erstellt automatisch einen Source-ZIP — kein manuelles Verpacken nötig.
Der `name`-Wert in `theme.json` muss mit dem Verzeichnisnamen übereinstimmen.

**Update-Mechanismus:**
Gleich wie bei Plugins — `version` in `theme.json` wird mit dem GitHub-Release verglichen.

---

## Checkliste neues Theme

- [ ] `theme.json` mit eindeutigem `name` (= Verzeichnisname)
- [ ] `Theme.php` mit korrektem Namespace und `boot()` + `renderPage()`
- [ ] `templates/layout.php` vorhanden
- [ ] `templates/error.php` vorhanden (404/403)
- [ ] **esse-grid Klassen implementiert** (Pflicht für Plugin-Kompatibilität)
- [ ] `$theme->assetUrl()` für CSS/Font-Pfade verwendet
- [ ] Login-geschützte Themes haben `templates/login.php`
- [ ] Menüpositionen in `theme.json` unter `menus` deklariert
- [ ] `data-bs-theme="dark"` auf `<html>` wenn Bootstrap mit dunklem Hintergrund
- [ ] README.md vorhanden
- [ ] ZIP ohne `.git/`, `.vscode/`, `node_modules/`
