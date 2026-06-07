# ESSE CMS — Theme-Entwicklung

## Grundstruktur

```
themes/mein-theme/
├── theme.json          ← Pflicht: Metadaten
├── Theme.php           ← Pflicht: PHP-Klasse
├── templates/
│   ├── layout.php      ← Pflicht: Haupt-Layout
│   └── error.php       ← Empfohlen: 404/403-Seite
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
        $siteName   = $this->settings['site_name']   ?? 'ESSE CMS';
        $siteSlogan = $this->settings['site_slogan'] ?? '';

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

Die folgenden Variablen stehen in `layout.php` und `error.php` zur Verfügung:

### Immer verfügbar

| Variable | Typ | Inhalt |
|---|---|---|
| `$page` | `array` | Aktuelle Seite (siehe unten) |
| `$content` | `string` | Gerenderter Seiteninhalt (HTML) |
| `$siteName` | `string` | Seitenname aus Einstellungen |
| `$siteSlogan` | `string` | Optionaler Slogan aus Einstellungen — **kann leer sein**. In dem Fall darf nichts angezeigt werden (kein Platzhaltertext, kein „ESSE CMS"-Eigenwerbung o.ä.) |
| `$theme` | `Theme` | Das Theme-Objekt selbst |

### $page — Felder

| Schlüssel | Typ | Inhalt |
|---|---|---|
| `slug` | `string` | URL-Slug der Seite |
| `title` | `string` | Seitentitel |
| `icon` | `string\|null` | Icon-Name (z.B. `house`) oder volle CSS-Klasse für Rückwärtskompatibilität |
| `visibility` | `string` | `public`, `guest_only`, `registered`, `roles` |
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
$item['icon']       // CSS-Klasse (optional, direkt als class-Attribut)
$item['target']     // '_self' oder '_blank'
$item['children']   // Array mit Untereinträgen
$item['active']     // true wenn aktuelle Seite

// URL auflösen:
\Esse\Menu::itemUrl($item)  // gibt '/slug' oder 'https://...' zurück
```

---

## Icon-Pack-CSS einbinden

`\Esse\Ui::icon()` erzeugt nur das HTML-Markup (z.B. `<i class="bi bi-house"></i>`). Die zugehörige CSS-Datei lädt der CMS-Core **nicht** automatisch — das Theme muss sie selbst in den `<head>` einbinden.

Dafür steht `\Esse\Ui::iconPackCssTag()` bereit. Es liest das aktive Pack aus der Datenbank und gibt den passenden `<link>`-Tag zurück:

```php
<head>
    ...
    <?= \Esse\Ui::iconPackCssTag() ?>
    ...
</head>
```

Falls nur die URL benötigt wird (z.B. für eigenes Markup oder preload):

```php
<link rel="preload" as="style" href="<?= \Esse\Ui::iconPackCssUrl() ?>">
<link rel="stylesheet" href="<?= \Esse\Ui::iconPackCssUrl() ?>">
```

Beide Methoden fallen auf Bootstrap Icons zurück wenn kein Pack aktiv ist.

> **Wichtig:** Themes die ihren kompletten `<head>` selbst rendern, müssen `iconPackCssTag()` einbinden — sonst bleiben alle Icons leer, egal ob `Ui::icon()` verwendet wird oder nicht.

## Icons rendern

`$page['icon']` enthält entweder einen pack-agnostischen Icon-Namen (`speedometer2`) oder — für Rückwärtskompatibilität — eine volle CSS-Klasse (`bi bi-speedometer2`). Themes müssen **beide Formen** unterstützen.

Empfohlenes Muster (wie esse-base):

```php
<?php if (!empty($page['icon'])): ?>
<?php
$pi = $page['icon'];
echo str_contains($pi, ' ')
    ? '<i class="' . htmlspecialchars($pi) . '"></i> '       // volle Klasse (Rückwärtskompatibilität)
    : \Esse\Ui::icon(preg_replace('/^(bi|ph|ti|lucide|ri)-/', '', $pi)) . ' '; // pack-agnostisch
?>
<?php endif ?>
```

`Ui::icon()` liest den Prefix aus der aktiven `iconpack.json` — das Theme muss das Pack nicht selbst kennen. Die CSS dafür muss aber (wie oben beschrieben) im `<head>` stehen.

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

// Icon-Pack (im <head> einbinden!)
\Esse\Ui::iconPackCssTag()   // → <link rel="stylesheet" href="/public/vendor/bootstrap-icons/...">
\Esse\Ui::iconPackCssUrl()   // → '/public/vendor/bootstrap-icons/bootstrap-icons.min.css'
```

---

## esse-ui — CSS laden

Jedes Theme **muss** `/public/vendor/esse-ui/esse-ui.css` laden damit Plugins korrekt dargestellt werden:

```html
<link rel="stylesheet" href="/public/vendor/esse-ui/esse-ui.css">
<link rel="stylesheet" href="<?= $theme->assetUrl('css/mein-theme.css') ?>">
```

Die esse-ui.css definiert CSS-Variablen die das Theme überschreiben kann:

```css
/* In der Theme-CSS-Datei: */
:root {
  --esse-bg:          #050508;      /* Hintergrund */
  --esse-surface:     #0d0d14;      /* Karten-/Panel-Hintergrund */
  --esse-border:      rgba(255,255,255,0.06); /* Rahmenfarbe */
  --esse-text:        #e8e6e0;      /* Haupttextfarbe */
  --esse-text-muted:  #9a9aa8;      /* Gedämpfte Textfarbe */
  --esse-radius:      0;            /* Border-Radius (0 = eckig) */
  --esse-primary:     #e8640a;      /* Akzentfarbe */
  --esse-success:     #22c55e;
  --esse-warning:     #eab308;
  --esse-danger:      #ef4444;
  --esse-info:        #38bdf8;
}
```

Darüber hinaus können einzelne esse-* Klassen gezielt überschrieben werden:

```css
/* Beispiel: esse-panel im Cyber-Stil */
.esse-panel {
  border-left: 2px solid var(--esse-primary);
  border-radius: 0;
  background: var(--esse-surface);
}
.esse-panel-header {
  font-family: 'Share Tech Mono', monospace;
  color: var(--esse-primary);
  text-transform: uppercase;
  letter-spacing: .1em;
  font-size: .7rem;
}
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
 * @var string      $siteSlogan
 * @var YourTheme   $theme
 */
$code    = (int) ($page['error_code']   ?? 404);
$title   = $page['error_title']         ?? 'Fehler';
$message = $page['error_message']       ?? '';
?>
<!DOCTYPE html>...
```

---

## Zugriffskontrolle: nichts für Themes zu tun

`PageRenderer::render()` (core/PageRenderer.php) prüft die Sichtbarkeit **zentral, bevor**
der `page.render`-Hook überhaupt feuert:

- `guest_only` + eingeloggt → Redirect auf `/`
- nicht-öffentlich + nicht eingeloggt → Redirect auf `/login?redirect=...`
- nicht-öffentlich + eingeloggt, aber ohne Rolle/Recht → 403

**Das bedeutet: `renderPage($page, $content)` wird nur für Seiten aufgerufen, auf die der
aktuelle Besucher bereits zugreifen darf.** Ein eigener `Auth::check()`-Zweig im Theme ist
unnötig — er ist in der Praxis sogar gefährlich, weil veraltete Sichtbarkeits-Werte
(`members`, `admin` aus dem alten 3-Werte-System statt der aktuellen `public`, `guest_only`,
`registered`, `roles`) leicht zu falsch gerenderten Seiten führen (z. B. `guest_only`-Seiten
wie `/registrieren`, die fälschlich ein Login-Formular zeigen).

`renderPage()` sollte daher **immer** einfach das normale Layout rendern:

```php
public function renderPage(array $page, string $content): void
{
    $siteName = $this->settings['site_name'] ?? 'ESSE CMS';
    $theme    = $this;
    require $this->basePath('templates/layout.php');
}
```

Ein eigenes `templates/login.php` bzw. `templates/public.php` wird **nicht** benötigt —
auch nicht für Dashboard-Themes. Wer dennoch eine eigene Login-Seite gestalten möchte,
nutzt dafür den `auth.login.render`-Hook (siehe nächster Abschnitt) statt die
Sichtbarkeitslogik im Theme zu duplizieren.

---

## Eigene Login-Seite gestalten (auth.login.render)

`/login` lässt sich vollständig im Theme-Design rendern — `/admin/login` bleibt davon
**immer unberührt** und zeigt weiterhin das Standard-Formular. Das ist der
Fail-Safe-Notausgang: geht im Theme etwas kaputt oder wird es deaktiviert, kommt man über
`/admin/login` trotzdem ins Backend.

Die komplette Auth-Logik (CSRF-Prüfung, Rate-Limiting, `Auth::attempt()`,
Redirect-Auflösung) bleibt zentral in `admin/login.php` — das Theme übernimmt
ausschließlich das Rendering.

**1. Hook in `boot()` registrieren:**

```php
public function boot(): void
{
    // ... bestehender Code ...
    Hooks::on('auth.login.render', [$this, 'renderLogin']);
}
```

**2. Renderer implementieren:**

```php
public function renderLogin(array $data): void
{
    $theme = $this;
    require $this->basePath('templates/login.php');
}
```

`$data` enthält:

| Schlüssel | Typ | Inhalt |
|---|---|---|
| `error` | `string` | Fehlermeldung (leer, wenn keine) |
| `redirect` | `string` | Wert für das versteckte `redirect`-Feld |
| `csrfToken` | `string` | CSRF-Token für das Formular |
| `brandName` | `string` | Seitenname aus Einstellungen |
| `brandSlogan` | `string` | Optionaler Slogan — kann leer sein, dann nichts anzeigen |
| `footMenu` | `array` | Footer-Menü des Themes (`Menu::get(...)`-Format) |
| `registrationEnabled` | `bool` | Ob `/registrieren` verlinkt werden soll |

**3. Template `templates/login.php` — Pflichtfelder im Formular:**

Das Formular muss exakt diese Felder an `POST /login` senden, sonst greift die zentrale
Auth-Logik nicht korrekt:

```php
<form method="post" action="/login">
    <input type="hidden" name="_csrf"    value="<?= htmlspecialchars($data['csrfToken']) ?>">
    <input type="hidden" name="_form"    value="admin_login">
    <input type="hidden" name="redirect" value="<?= htmlspecialchars($data['redirect']) ?>">
    <input type="email"    name="login"    autocomplete="username" required>
    <input type="password" name="password" autocomplete="current-password" required>
    <button type="submit">Anmelden</button>
</form>
```

`name="_form" value="admin_login"` ist **Pflicht** — ohne dieses Feld behandelt die
zentrale Logik den Login-Versuch wie ein eingebettetes Navbar-Formular und leitet bei
Fehlern zurück zur vorigen Seite statt den Fehler auf `/login` selbst anzuzeigen.

**4. Optional:** Links auf `/admin/forgot-password` und (wenn `$data['registrationEnabled']`)
`/registrieren` ergänzen — siehe `admin/login.php` als Referenzimplementierung für das
Standard-Rendering.

### Verwandte Hooks: Passwort vergessen / zurücksetzen

Nach demselben Muster lassen sich auch die beiden Passwort-Seiten anpassen — anders als
beim Login gibt es hier **keinen Fail-Safe-Alias** (z. B. kein `/admin/...`-Pendant): Die
Hooks feuern direkt auf `/admin/forgot-password` bzw. `/admin/reset-password`. Das ist
bewusst so, da diese Seiten weniger kritisch sind als Login — schlägt das Rendering fehl,
können Admins Passwörter weiterhin manuell über die Benutzerverwaltung zurücksetzen.

**`auth.forgot_password.render`** — Daten:

| Schlüssel | Typ | Inhalt |
|---|---|---|
| `sent` | `bool` | Ob die Anfrage erfolgreich verarbeitet wurde (Erfolgsmeldung zeigen) |
| `errors` | `array` | Fehlermeldungen |
| `csrfToken` | `string` | CSRF-Token |
| `captchaQuestion` | `string` | Rechenaufgabe als Text (z. B. `"3 + 5"`), leer wenn `sent` |
| `honeypotField` | `string` | Feldname für das versteckte Honeypot-Feld (`Captcha::HONEYPOT_FIELD`) |
| `brandName` / `brandSlogan` | `string` | Branding aus den Einstellungen |

Formular muss `email`, `captcha_answer` (= `{{ captchaQuestion }} = ?`) und das
Honeypot-Feld (`name="<?= $data['honeypotField'] ?>"`, versteckt, `tabindex="-1"`) an
`POST /admin/forgot-password` senden — siehe `admin/forgot-password.php` als Referenz.

**`auth.reset_password.render`** — Daten:

| Schlüssel | Typ | Inhalt |
|---|---|---|
| `success` | `bool` | Passwort erfolgreich geändert |
| `errors` | `array` | Fehlermeldungen |
| `token` | `string` | Reset-Token aus der URL |
| `valid` | `bool` | Ob der Token gültig/nicht abgelaufen ist |
| `csrfToken` | `string` | CSRF-Token |
| `brandName` / `brandSlogan` | `string` | Branding aus den Einstellungen |

Formular muss `password`, `password_confirm` und `token` (verstecktes Feld) an
`POST /admin/reset-password` senden — siehe `admin/reset-password.php` als Referenz.

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
- [ ] `/public/vendor/esse-ui/esse-ui.css` geladen (vor Theme-CSS)
- [ ] CSS-Variablen (`--esse-*`) für Theme-Farben gesetzt
- [ ] **esse-grid Klassen implementiert** (Pflicht für Plugin-Kompatibilität)
- [ ] `$theme->assetUrl()` für CSS/Font-Pfade verwendet
- [ ] `renderPage()` enthält **keinen** eigenen `Auth::check()`/Sichtbarkeits-Zweig (das übernimmt `PageRenderer` zentral)
- [ ] Menüpositionen in `theme.json` unter `menus` deklariert
- [ ] `\Esse\Ui::iconPackCssTag()` im `<head>` eingebunden (Pflicht — Core lädt die CSS nicht automatisch)
- [ ] `$page['icon']` wird pack-agnostisch über `Ui::icon()` gerendert (volle CSS-Klassen als Fallback)
- [ ] `data-bs-theme="dark"` auf `<html>` wenn Bootstrap mit dunklem Hintergrund
- [ ] README.md vorhanden
- [ ] ZIP ohne `.git/`, `.vscode/`, `node_modules/`
