# ESSE CMS — Plugin-Entwicklung

## Inhalt

- [Grundstruktur](#grundstruktur)
- [plugin.json](#pluginjson)
- [Plugin.php — Mindestaufbau](#pluginphp--mindestaufbau)
- [Verfügbare Methoden in boot()](#verfügbare-methoden-in-boot)
- [Autoloading](#autoloading)
- [Verfügbare Konstanten](#verfügbare-konstanten)
- [Plugin-Einstellungen](#plugin-einstellungen)
- [$activeNav für mehrere Plugin-Seiten](#activenav-für-mehrere-plugin-seiten)
- [Datenbankzugriff](#datenbankzugriff)
- [Aktuellen User abfragen](#aktuellen-user-abfragen)
- [E-Mail senden](#e-mail-senden)
- [Sensible Daten verschlüsseln](#sensible-daten-verschlüsseln)
- [CSRF bei AJAX-Requests](#csrf-bei-ajax-requests)
- [Flash-Messages](#flash-messages)
- [Admin-Templates mit Layout](#admin-templates-mit-layout)
- [Summernote (WYSIWYG) in Plugin-Admin-Seiten](#summernote-wysiwyg-in-plugin-admin-seiten)
- [Icon-Felder](#icon-felder)
- [Dashboard-Theme-Kompatibilität](#dashboard-theme-kompatibilität)
- [Plugin-Assets](#plugin-assets)
- [ZIP-Packaging](#zip-packaging)
- [Eigenes Icon-Pack bereitstellen](#eigenes-icon-pack-bereitstellen)
- [Komplettes Beispiel](#komplettes-beispiel)
- [Ui-Klasse — Theme-agnostische Komponenten](#ui-klasse--theme-agnostische-komponenten)
- [Theme-agnostische Ausgabe](#theme-agnostische-ausgabe)
- [Plugin veröffentlichen (GitHub-Repo)](#plugin-veröffentlichen-github-repo)
- [README-Vorlage](#readme-vorlage)
- [Checkliste neues Plugin](#checkliste-neues-plugin)

---

## Grundstruktur

```
plugins/mein-plugin/
├── plugin.json       ← Pflicht: Metadaten
├── Plugin.php        ← Pflicht: Hauptklasse
├── README.md         ← Empfohlen
├── CHANGELOG.md      ← Empfohlen
├── LICENSE           ← Empfohlen (z.B. MIT, AGPL-3.0)
└── ...               ← eigene PHP-Dateien, Templates, Assets
```

---

## plugin.json

```json
{
    "name": "esse-news",
    "version": "1.0.0",
    "description": "Kurzbeschreibung des Plugins.",
    "author": "Dein Name",
    "class": "EsseNews\\Plugin",
    "requires": {
        "esse": ">=0.1.0"
    }
}
```

**Wichtig:** `name` muss dem Verzeichnisnamen entsprechen (`plugins/esse-news/`).

---

## Plugin.php — Mindestaufbau

```php
<?php

declare(strict_types=1);

namespace MeinPlugin;

use Esse\Router;

class Plugin extends \Esse\Plugin
{
    public function boot(): void
    {
        // Wird bei JEDEM Request aufgerufen wenn das Plugin aktiv ist.
        // DB-Migration MUSS hier stehen — install() wird nicht zuverlässig aufgerufen.
        MyRepository::migrate(); // CREATE TABLE IF NOT EXISTS
    }

    public function install(): void
    {
        // Wird bei NEUINSTALLATION via ZIP aufgerufen (nicht bei Updates).
        // Optional: Seed-Daten einfügen, Einstellungen initialisieren.
        // DB-Tabellen hier NICHT anlegen — das gehört in boot().
    }

    public function uninstall(): void
    {
        // Wird beim Deinstallieren aufgerufen.
        // DB-Tabellen löschen, Einstellungen entfernen.
    }
}
```

> **Wichtig zu install():** DB-Migrationen (`CREATE TABLE IF NOT EXISTS`) gehören in `boot()`,
> weil `install()` nur bei Neuinstallation per ZIP aufgerufen wird. Bei manuell kopierten
> Plugins oder bestimmten Fehlerzuständen kann `install()` ausbleiben. `boot()` läuft immer.

---

## Verfügbare Methoden in boot()

### Routen registrieren

```php
// Frontend-Route (nackte Ausgabe — KEIN Theme-Wrapper!)
Router::get('/news', fn() => require $this->basePath('frontend/list.php'),
    ['name' => 'news.list', 'auth' => 'public']);

// Admin-Route
Router::get('/admin/news', fn() => require $this->basePath('admin/list.php'),
    ['name' => 'admin.news', 'auth' => 'admin']);

// Route mit Parameter ({param} matched alles außer /)
Router::get('/news/{id}', function (string $id) {
    $newsId = (int) $id;
    require $this->basePath('frontend/detail.php');
}, ['name' => 'news.detail', 'auth' => 'public']);

// GET + POST auf gleicher URL (Formular-Pattern)
Router::get('/news/create', fn() => require $this->basePath('frontend/create.php'),
    ['name' => 'news.create.get', 'auth' => 'member']);
Router::post('/news/create', fn() => require $this->basePath('frontend/create.php'),
    ['name' => 'news.create.post', 'auth' => 'member']);
```

**auth-Werte:** `public`, `member`, `author`, `editor`, `admin`, `forge`,
oder ein Permission-Slug wie `php_upload`

### Frontend-Output: mit oder ohne Theme-Wrapper

Das ist der häufigste Stolperstein. Ein einfaches `require` gibt **rohes HTML** aus — kein Theme, kein Navbar, kein Footer.

**Pattern 1 — Direkt via `ob_start()` (empfohlen, transparent):**

```php
$base = $this->basePath();
Router::get('/news', function() use ($base) {
    ob_start();
    require "{$base}/frontend/list.php";
    $content = ob_get_clean();

    $page = ['title' => 'News', 'slug' => 'news', 'visibility' => 'public'];

    if (\Esse\Hooks::has('page.render')) {
        \Esse\Hooks::fire('page.render', $page, $content);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo $content;
    }
}, ['name' => 'news.list', 'auth' => 'public']);
```

**Pattern 2 — Via `PageRenderer::renderFile()` (Kurzform):**

```php
$base = $this->basePath();
Router::get('/news', function() use ($base) {
    \Esse\PageRenderer::renderFile("{$base}/frontend/list.php", 'News');
    // Drittes Argument: visibility ('public', 'members', 'admin')
    // Default: 'public'
}, ['name' => 'news.list', 'auth' => 'public']);
```

Beide Patterns funktionieren gleichwertig. Pattern 1 gibt dir mehr Kontrolle über das `$page`-Array das ans Theme übergeben wird.

**Es gibt kein `frontend/layout.php`** — das Theme übernimmt das Layout vollständig über den `page.render`-Hook.

> **Doppelter `<h1>` vermeiden:**
> Das aktive Theme rendert `$page['title']` bereits als `<h1>` vor dem Content.
> Das Frontend-Template darf deshalb **keinen eigenen `<h1>` mit dem Seitentitel** ausgeben —
> sonst erscheint "News" zweimal. Starte den Template-Output direkt mit dem Inhalt,
> nicht mit einer Überschrift die den Titel wiederholt.

### 404 und Fehler aus Plugins

```php
// CMS-404-Seite aufrufen (nutzt aktives Theme):
\Esse\Router::abort(404);
return;

// 403 Forbidden:
\Esse\Router::abort(403);
return;
```

`Router::abort()` setzt den HTTP-Status und rendert die Theme-Fehlerseite. Danach einfach `return` — kein `exit` nötig.

> **Wichtig — Router-Einschränkungen:**
>
> `{param}` matched nur Zeichen ohne `/`. Für Pfade mit Unterordnern
> (z.B. `/files/ordner/datei.pdf`) ist `{param}` nicht geeignet —
> verwende stattdessen GET-Parameter: `/files?path=ordner/datei.pdf`
>
> **class constants können keine Runtime-Konstanten enthalten:**
> ```php
> // FALSCH — wirft Fatal Error:
> class MyPlugin extends \Esse\Plugin {
>     const UPLOAD_DIR = ESSE_ROOT . '/plugins/...'; // geht nicht!
> }
>
> // RICHTIG — in boot() oder Methoden:
> public function boot(): void {
>     $dir = $this->basePath('uploads/');  // immer so
> }
> ```

### Admin-Sidebar-Eintrag

```php
$this->addAdminNav(
    'News',           // Label in der Sidebar
    '/admin/news',    // URL
    'newspaper',      // Icon-Name (ohne Pack-Prefix)
    'admin.news'      // activeSlug — MUSS mit $activeNav in den Admin-Templates übereinstimmen!
);
```

> **Häufiger Fehler:** Wenn der Sidebar-Link nicht aktiv hervorgehoben wird, stimmt
> der `activeSlug` nicht mit `$activeNav` im Template überein.

### Frontend-Seiten beim CMS anmelden

Damit die Seite in der Seiten-Liste erscheint, im Menü-Dropdown auswählbar ist
und Slug-Konflikte erkannt werden:

```php
$this->registerPage('/news',      'News-Liste',  'newspaper');
$this->registerPage('/news/{id}', 'News-Detail', 'newspaper');
```

### Hooks verwenden

```php
$this->on('page.render', function(array $page, string $content) {
    // Inhalte vor dem Rendern modifizieren
});

\Esse\Hooks::on('my.event', fn() => ...);
```

---

## Autoloading

ESSE hat **keinen PSR-4-Autoloader für Plugins**. Der Core-Autoloader kennt nur den `Esse\`-Namespace.

Eigene Klassen müssen manuell geladen werden:

```php
// In Plugin.php, am Anfang der Datei (außerhalb der Klasse):
require_once __DIR__ . '/NewsRepository.php';
require_once __DIR__ . '/NewsItem.php';

// Oder in boot():
public function boot(): void
{
    require_once $this->basePath('NewsRepository.php');
    // ...
}
```

Wer einen eigenen PSR-4-Autoloader will, kann ihn in `boot()` registrieren:
```php
spl_autoload_register(function(string $class): void {
    if (!str_starts_with($class, 'EsseNews\\')) return;
    $file = $this->basePath(str_replace('\\', '/', substr($class, 9)) . '.php');
    if (file_exists($file)) require_once $file;
});
```

---

## Verfügbare Konstanten

| Konstante | Inhalt |
|---|---|
| `ESSE_ROOT` | Absoluter Pfad zum CMS-Verzeichnis |
| `ESSE_PRIVATE_PATH` | Pfad zum privaten Verzeichnis (kann außerhalb Webroot liegen) |
| `ESSE_VERSION` | Aktuelle CMS-Version (z.B. `'0.1.0-alpha'`) |
| `ESSE_GITHUB_REPO` | GitHub-Repo für Updates (`'nfsmw15/esse-cms'`) |
| `ESSE_DB_HOST` | Datenbank-Host (aus config.php) |
| `ESSE_DB_NAME` | Datenbankname |
| `ESSE_DB_PREFIX` | Tabellen-Prefix (z.B. `'esse_'`) |
| `ESSE_URL` | Site-URL ohne abschließenden Slash |
| `ESSE_ENCRYPT_KEY` | Verschlüsselungsschlüssel für `Crypto::encrypt()` |

> **Achtung:** Diese Konstanten sind Runtime-Werte. Sie können **nicht** in Class-Constants verwendet werden:
> ```php
> // FEHLER:
> class MyPlugin extends \Esse\Plugin {
>     const DIR = ESSE_ROOT . '/plugins/mein-plugin'; // Fatal Error!
> }
> // RICHTIG:
> public function boot(): void {
>     $dir = $this->basePath(); // immer so
> }
> ```

---

## Plugin-Einstellungen

Einstellungen werden in der `esse_settings`-Tabelle gespeichert. Empfohlenes Key-Format: `plugin_{name}_{schluessel}`.

```php
use Esse\DB;

$ts = DB::table('settings');

// Lesen (mit Standardwert)
$perPage = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'plugin_esse-news_per_page'") ?? '10';

// Schreiben
DB::query(
    "INSERT INTO `{$ts}` (`key`, `value`) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
    ['plugin_esse-news_per_page', '20']
);

// Mehrere Einstellungen auf einmal lesen
$rows = DB::fetchAll("SELECT `key`, `value` FROM `{$ts}` WHERE `key` LIKE 'plugin_esse-news_%'");
$settings = array_column($rows, 'value', 'key');
$perPage  = $settings['plugin_esse-news_per_page'] ?? '10';
```

---

## $activeNav für mehrere Plugin-Seiten

`$activeNav` ist ein einfacher String-Vergleich mit dem `activeSlug` aus `addAdminNav()`.
Setze `$activeNav` auf denselben Wert auf **allen** Admin-Seiten des Plugins:

```php
// In admin/list.php UND admin/form.php UND admin/settings.php:
$activeNav = 'admin.news'; // immer gleich → Sidebar-Link bleibt aktiv
```

---

## Datenbankzugriff

```php
use Esse\DB;

$table = DB::table('news');  // → 'esse_news' (mit konfiguriertem Prefix)

$items = DB::fetchAll("SELECT * FROM `{$table}` ORDER BY created_at DESC");
$item  = DB::fetch("SELECT * FROM `{$table}` WHERE id = ?", [$id]);
$count = DB::value("SELECT COUNT(*) FROM `{$table}`");

$id = DB::insert($table, ['title' => 'Titel', 'content' => '...']);
DB::update($table, ['title' => 'Neu'], ['id' => $id]);
DB::delete($table, ['id' => $id]);

DB::query("CREATE TABLE IF NOT EXISTS `{$table}` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title`      VARCHAR(255) NOT NULL,
    `content`    LONGTEXT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
```

---

## Aktuellen User abfragen

```php
use Esse\Auth;

Auth::check();              // bool: eingeloggt?
Auth::user();               // array: User-Daten (id, display_name, email, role)
Auth::id();                 // int: User-ID
Auth::role();               // string: 'forge', 'admin', 'editor', 'author', 'member'
Auth::meetsRole('editor');  // bool: mindestens Editor?
Auth::can('php_upload');    // bool: hat Permission?
Auth::csrfToken();          // string: CSRF-Token für Formulare
Auth::verifyCsrf();         // bool: CSRF prüfen (immer in POST-Handlern aufrufen!)
```

---

## E-Mail senden

```php
use Esse\Mailer;

Mailer::send(
    'empfaenger@example.com',
    'Max Mustermann',          // Anzeigename
    'Betreff',
    '<p>HTML-Inhalt</p>'       // isHtml = true (Standard)
);

// Plain-Text
Mailer::send('to@example.com', 'Name', 'Betreff', 'Nur Text', false);
```

Wirft `\RuntimeException` wenn SMTP nicht konfiguriert ist.

---

## Sensible Daten verschlüsseln

```php
use Esse\Crypto;
use Esse\DB;

$ts = DB::table('settings');

// Speichern (verschlüsselt)
DB::query(
    "INSERT INTO `{$ts}` (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
    ['mein_plugin_api_key', Crypto::encrypt($apiKey)]
);

// Lesen (entschlüsselt)
$encrypted = DB::value("SELECT `value` FROM `{$ts}` WHERE `key`='mein_plugin_api_key'");
$apiKey    = Crypto::decrypt($encrypted ?? '');
```

---

## CSRF bei AJAX-Requests

Für `fetch()`/XHR das Token aus einem vorhandenen `<input>` auslesen:

```javascript
// Token aus dem Formular auf der Seite holen
const csrf = document.querySelector('input[name="_csrf"]')?.value;

fetch('/admin/esse-news/action', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': csrf },  // Header-Variante
    body: JSON.stringify({ id: 123 }),
});

// Oder als FormData:
const fd = new FormData();
fd.append('_csrf', csrf);
fd.append('data', 'wert');
fetch('/admin/esse-news/action', { method: 'POST', body: fd });
```

PHP-Seite prüft beides (`$_POST['_csrf']` oder `HTTP_X_CSRF_TOKEN`):
```php
if (!Auth::verifyCsrf()) { http_response_code(403); exit; }
```

Wenn kein Formular auf der Seite ist, CSRF-Token als JS-Variable rendern:
```php
// In der Admin-Seite:
$pageTitle = 'News';
$activeNav = 'admin.news';
ob_start();
?>
<script>const CSRF = <?= json_encode(Auth::csrfToken()) ?>;</script>
<div id="app">...</div>
<?php
$content = ob_get_clean();
require ESSE_ROOT . '/admin/layout.php';
```

---

## Flash-Messages

```php
// Setzen (vor einem Redirect)
$_SESSION['flash'] = ['type' => 'success', 'message' => 'Gespeichert.'];
header('Location: /admin/mein-plugin');
exit;

// Das Admin-Layout rendert $flash automatisch.
// type: 'success', 'danger', 'warning', 'info'
```

---

## Admin-Templates mit Layout

```php
// plugins/mein-plugin/admin/list.php

use Esse\Auth;
use Esse\DB;

$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

$pageTitle = 'News';
$activeNav = 'admin.news';  // MUSS exakt mit activeSlug aus addAdminNav() übereinstimmen!

ob_start();
?>
<h1>Meine Plugin-Seite</h1>
<?php
$content = ob_get_clean();
require ESSE_ROOT . '/admin/layout.php';
```

### Zusätzliche Styles und Scripts

Das Admin-Layout unterstützt `$extraHead` (im `<head>`) und `$extraScripts` (vor `</body>`):

```php
$extraHead = '<link rel="stylesheet" href="/public/vendor/mein-plugin/style.css">';

$extraScripts = '<script src="/public/vendor/mein-plugin/script.js"></script>
<script>
// Initialisierung nach dem Laden der Scripts
</script>';

$content = ob_get_clean();
require ESSE_ROOT . '/admin/layout.php';
```

### Topbar-Aktions-Button

Mit `$topbarRight` kann ein Button neben dem Seitentitel in der Topbar platziert werden:

```php
$topbarRight = '<a href="/admin/news/create" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg"></i> Neuer Eintrag
</a>';
```

---

## Summernote (WYSIWYG) in Plugin-Admin-Seiten

Das CMS liefert Summernote BS5 mit jQuery aus. So bindet man es in einer Plugin-Admin-Seite ein:

```php
$extraHead = '<link rel="stylesheet" href="/public/vendor/summernote/summernote-bs5.min.css">
<style>
.note-editor     { border-color:#333 !important; }
.note-toolbar    { background:#1e1e1e !important; border-color:#333 !important; }
.note-toolbar .btn { color:#adb5bd; background:transparent; border-color:#333; }
.note-toolbar .btn:hover, .note-toolbar .btn.active { background:#2d2d2d; color:#fff; }
.note-editable   { background:#111 !important; color:#e0e0e0 !important; min-height:300px; }
.note-statusbar  { background:#1a1a1a !important; border-color:#333 !important; }
.dropdown-menu   { background:#1e1e1e; border-color:#333; }
.dropdown-item   { color:#adb5bd; }
.dropdown-item:hover { background:#2d2d2d; color:#fff; }
</style>';

$extraScripts = '<script src="/public/vendor/summernote/jquery.min.js"></script>
<script src="/public/vendor/summernote/summernote-bs5.min.js"></script>
<script src="/public/vendor/summernote/summernote-de-DE.min.js"></script>
<script>
// Bootstrap 5 ↔ jQuery bridge (benötigt für Summernote-Tooltips)
$.fn.tooltip = function(opt) {
    return this.each(function() {
        if (typeof opt === "string") { const t = bootstrap.Tooltip.getInstance(this); if (t) t[opt](); }
        else new bootstrap.Tooltip(this, opt || {});
    });
};
$.fn.popover = function(opt) {
    return this.each(function() {
        if (typeof opt === "string") { const p = bootstrap.Popover.getInstance(this); if (p) p[opt](); }
        else new bootstrap.Popover(this, opt || {});
    });
};
(function() {
    $("#mein-textarea").summernote({
        lang: "de-DE",
        height: 350,
        toolbar: [
            ["style",  ["style"]],
            ["font",   ["bold","italic","underline","clear"]],
            ["para",   ["ul","ol","paragraph"]],
            ["insert", ["link","picture","hr"]],
            ["view",   ["fullscreen","codeview"]]
        ],
        callbacks: {
            onImageUpload: function(files) {
                const fd = new FormData();
                fd.append("file", files[0]);
                fetch("/admin/files/upload", { method: "POST", body: fd })
                    .then(r => r.json())
                    .then(d => {
                        if (d.url) $("#mein-textarea").summernote("insertImage", d.url, files[0].name);
                        else alert(d.error || "Upload fehlgeschlagen.");
                    });
            }
        }
    });
    // Textarea-ID im HTML verstecken (Summernote ersetzt sie)
    document.getElementById("mein-textarea").style.display = "none";
})();
</script>';
```


---

## Icon-Felder

Seiten und Menü-Einträge haben ein optionales `icon`-Feld:

```php
// Seite programmatisch mit Icon anlegen:
DB::insert(DB::table('pages'), [
    'slug'       => 'dashboard',
    'title'      => 'Dashboard',
    'icon'       => 'speedometer2',  // optional — nur Icon-Name, kein Pack-Prefix
    'content'    => '',
    'type'       => 'standard',
    'visibility' => 'members',
    'status'     => 'published',
    'author_id'  => Auth::id(),
]);

// Menü-Eintrag mit Icon anlegen:
DB::insert(DB::table('menu_items'), [
    'menu_id'    => $menuId,
    'type'       => 'page',
    'label'      => 'Dashboard',
    'icon'       => 'bi bi-speedometer2',  // Menüs: volle CSS-Klasse
    'page_slug'  => 'dashboard',
    'sort_order' => 10,
    'active'     => 1,
]);
```

Themes rendern das Icon automatisch vor Label/Titel wenn `icon` gesetzt ist.

**Seiten-Icons:** Nur den Icon-Namen angeben (`speedometer2`), das aktive Icon-Pack liefert den Prefix.
Volle CSS-Klassen (`bi bi-speedometer2`) funktionieren weiterhin (Rückwärtskompatibilität).

**Menü-Icons:** esse-base rendert `$item['icon']` direkt als CSS-Klasse — hier volle Klasse angeben.

---

## Dashboard-Theme-Kompatibilität

Das esse-dashboard-Theme zeigt für nicht eingeloggte Nutzer eine Login-Seite,
**außer** die Seite hat `visibility = 'public'`.

**Plugin-Routen und das Dashboard-Theme:**

```php
// Mit PageRenderer::renderFile() — Theme prüft Sichtbarkeit:
$base = $this->basePath();
Router::get('/portal', function() use ($base) {
    \Esse\PageRenderer::renderFile("{$base}/frontend/portal.php", 'Portal', 'members');
    //                                                                        ↑ visibility
}, ['auth' => 'member']);

// Mit einfachem require — Theme wird KOMPLETT umgangen:
// → Immer rohes HTML, kein Login-Check vom Dashboard-Theme
Router::get('/api/data', fn() => require $this->basePath('api/data.php'),
    ['auth' => 'member']); // Auth-Check vom Router bleibt aktiv
```

> `PageRenderer::renderFile()` hat einen optionalen dritten Parameter `$visibility`
> (Standard: `'public'`). Setze ihn auf `'members'` damit das Dashboard-Theme
> Login-Check durchführt.

---

## Plugin-Assets

### assetUrl()

```php
// URL zu einer Datei in plugins/mein-plugin/public/
$this->assetUrl('css/style.css')
// → https://example.com/plugins/mein-plugin/public/css/style.css

$this->assetUrl('images/logo.png')
// → https://example.com/plugins/mein-plugin/public/images/logo.png
```

> **Achtung:** `assetUrl()` baut die URL nur — die Datei muss trotzdem
> web-zugänglich sein. Der `plugins/`-Ordner ist per `.htaccess` gesperrt,
> also muss die Datei über eine Route oder aus `public/vendor/` ausgeliefert werden
> (siehe unten).

### DB-Migrationen bei Plugin-Updates

`install()` wird nur bei **Neuinstallation** aufgerufen. Wenn du in Version 1.1
eine neue Spalte brauchst, muss die Migration in `boot()` stehen:

```php
public function boot(): void
{
    // Läuft bei JEDEM Request — immer idempotent schreiben!
    DB::query("CREATE TABLE IF NOT EXISTS `" . DB::table('news') . "` (...)");

    // Neue Spalte in v1.1 — IF NOT EXISTS verhindert Fehler bei Bestandsinstallationen:
    DB::query("ALTER TABLE `" . DB::table('news') . "`
               ADD COLUMN IF NOT EXISTS `image` VARCHAR(500) DEFAULT NULL");
}
```

`ALTER TABLE ... ADD COLUMN IF NOT EXISTS` ist MySQL/MariaDB-spezifisch und
wirft keinen Fehler wenn die Spalte schon existiert.

### Der `plugins/`-Ordner ist per `.htaccess` gesperrt. Für öffentliche Assets gibt es zwei Wege:

**Option A: Route (einfach, keine Kopierschritte)**
```php
Router::get('/plugins/esse-news/assets/{file}', function(string $file) {
    $path = $this->basePath('assets/' . basename($file));
    if (!file_exists($path)) { http_response_code(404); exit; }
    $mime = mime_content_type($path) ?: 'application/octet-stream';
    header("Content-Type: {$mime}");
    readfile($path);
}, ['auth' => 'public']);
```

**Option B: In `public/vendor/` ablegen** (bei install() kopieren, bei uninstall() löschen)

---

## ZIP-Packaging

Für den Upload über Admin → Plugins muss das Plugin als `.zip` vorliegen.

**Erlaubte Strukturen:**
```
# Mit Root-Ordner (empfohlen, GitHub-Downloads haben das automatisch):
esse-news/
├── plugin.json
├── Plugin.php
└── ...

# Ohne Root-Ordner:
plugin.json
Plugin.php
...
```

Der Root-Ordner wird beim Installieren automatisch erkannt und entfernt.

**ZIP erstellen (Kommandozeile):**
```bash
# Im Elternverzeichnis des Plugins:
zip -r esse-news-v1.0.0.zip esse-news/ \
  --exclude "*.git*" \
  --exclude "*/.vscode/*" \
  --exclude "*/node_modules/*" \
  --exclude "*/.DS_Store"
```

---

## Eigenes Icon-Pack bereitstellen

Icon-Packs werden über **Admin → Icon-Packs** als ZIP installiert und sind vom Plugin-System getrennt — ein Plugin kann kein Icon-Pack automatisch mitinstallieren.

### iconpack.json

```json
{
    "name": "mein-iconpack",
    "version": "1.0.0",
    "description": "Kurzbeschreibung.",
    "prefix": "mi mi-",
    "css": "mein-iconpack.min.css"
}
```

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `name` | ✓ | Eindeutiger Bezeichner (= Verzeichnisname unter `public/vendor/`) |
| `version` | ✓ | Semantic Versioning |
| `description` | | Kurzbeschreibung |
| `prefix` | ✓ | CSS-Klassen-Prefix inkl. Leerzeichen, z.B. `bi bi-` oder `fa-solid fa-` |
| `css` | ✓ | Dateiname der CSS-Datei im Pack-Verzeichnis |

### ZIP-Struktur

```
mein-iconpack/
├── iconpack.json
├── mein-iconpack.min.css
└── fonts/
    ├── mein-iconpack.woff
    └── mein-iconpack.woff2
```

Root-Ordner wird beim Installieren automatisch erkannt und entfernt. Nach der Installation liegt das Pack unter `public/vendor/mein-iconpack/`.

### Wie `Ui::icon()` den Pack nutzt

`Ui::icon('house')` liest den Prefix aus der aktiven `iconpack.json` und baut daraus die CSS-Klasse:

```php
// Bootstrap Icons aktiv (prefix: "bi bi-"):
Ui::icon('house')  // → <i class="bi bi-house"></i>

// Eigenes Pack aktiv (prefix: "mi mi-"):
Ui::icon('house')  // → <i class="mi mi-house"></i>
```

Deshalb: immer nur den Icon-Namen übergeben — nie die volle CSS-Klasse.
Volle Klassen (`bi bi-house`) werden als Rückwärtskompatibilität noch erkannt, sind aber nicht empfohlen.

---

## Komplettes Beispiel

`Plugin.php`:
```php
<?php
namespace EsseNews;
use Esse\Router;

require_once __DIR__ . '/NewsRepository.php';

class Plugin extends \Esse\Plugin
{
    public function boot(): void
    {
        NewsRepository::migrate();  // DB-Migration IMMER hier

        $this->addAdminNav('News', '/admin/news', 'newspaper', 'admin.news');
        $this->registerPage('/news',      'News',        'newspaper');
        $this->registerPage('/news/{id}', 'News-Detail', 'newspaper');

        $base = $this->basePath();
        Router::get('/news', fn() => require "{$base}/frontend/list.php",
            ['name' => 'news.list', 'auth' => 'public']);
        Router::get('/news/{id}', function(string $id) use ($base) {
            $newsId = (int) $id;
            require "{$base}/frontend/detail.php";
        }, ['name' => 'news.detail', 'auth' => 'public']);
        Router::get('/admin/news', fn() => require "{$base}/admin/list.php",
            ['name' => 'admin.news', 'auth' => 'admin']);
    }

    public function install(): void   { /* Seed-Daten etc. */ }
    public function uninstall(): void { NewsRepository::drop(); }
}
```

---

## Ui-Klasse — Theme-agnostische Komponenten

ESSE CMS stellt eine zentrale `Ui`-Klasse bereit. Plugins sollen **keine** Bootstrap-Klassen
mehr direkt ausgeben, sondern `Ui::*`-Methoden verwenden. Themes stylen die esse-* CSS-Klassen
nach ihrem eigenen Design.

```php
use Esse\Ui;

// Panel (Container mit Titel)
echo Ui::panel('Meine Alben', $content);
echo Ui::panel('Fehler', $msg, ['variant' => 'danger']);
echo Ui::panel('Galerie', $grid, [
    'icon'   => 'bi bi-images',
    'footer' => Ui::button('Album hinzufügen', '/gallery/create', ['variant' => 'primary']),
]);

// Buttons
echo Ui::button('Speichern', '/gallery/save', ['method' => 'post', 'icon' => 'floppy']);
echo Ui::button('Löschen',   '/gallery/delete', ['variant' => 'danger', 'method' => 'post', 'csrf' => true]);
echo Ui::button('Abbrechen', '/gallery', ['variant' => 'ghost']);
echo Ui::button('Details',   '/gallery/1', ['size' => 'sm']);

// Alerts / Benachrichtigungen
echo Ui::alert('success', 'Album gespeichert.');
echo Ui::alert('danger',  'Fehler beim Hochladen.', ['dismissible' => true]);

// Badges
echo Ui::badge('NEU', 'success');
echo Ui::badge('Entwurf', 'warning');
echo Ui::badge('7 Fotos');

// Grid (nutzt esse-grid unter der Haube)
$cards = ['<div>Bild 1</div>', '<div>Bild 2</div>', '<div>Bild 3</div>'];
echo Ui::grid($cards, ['cols' => 4]);

// Empty State
echo Ui::emptyState(
    'Noch keine Alben',
    'Erstelle dein erstes Album um Fotos zu organisieren.',
    [
        'icon'   => 'bi bi-images',
        'action' => Ui::button('Album erstellen', '/gallery/create'),
    ]
);

// Section (Abschnitt mit Titel)
echo Ui::section('Letzte Uploads', $imageList, [
    'action' => Ui::button('Alle anzeigen', '/gallery', ['size' => 'sm', 'variant' => 'ghost']),
]);

// Tabelle
echo Ui::table(
    ['Name', 'Größe', 'Datum'],
    [
        ['foto.jpg', '2,1 MB', '01.06.2026'],
        ['banner.png', '540 KB', '02.06.2026'],
    ]
);

// Tabs
echo Ui::tabs([
    ['label' => 'Alben',   'content' => $albumGrid,  'active' => true],
    ['label' => 'Uploads', 'content' => $uploadList],
]);

// Breadcrumb
echo Ui::breadcrumb([
    ['label' => 'Downloads', 'url' => '/downloads'],
    ['label' => 'Ordner A',  'url' => '/downloads/ordner-a'],
    ['label' => 'Datei.zip'],   // Letzter Eintrag = aktuell, kein Link
]);

// Divider / Trennlinie
echo Ui::divider();                              // Standard-Abstand
echo Ui::divider(['spacing' => 'lg']);           // Mehr Abstand
echo Ui::divider(['label' => 'Oder so']);        // Mit zentriertem Label
echo Ui::divider(['spacing' => 'none']);         // Kein Margin

// Icons mit Farbe und Größe
echo Ui::icon('images', '', ['color' => 'primary', 'size' => 'xl']);
echo Ui::icon('check-circle', '', ['color' => 'success']);
echo Ui::icon('exclamation-triangle', '', ['color' => 'warning', 'size' => 'lg']);

// Grid-Items als Links (klickbare Kacheln)
$items = [
    ['content' => '<img src="..."> ', 'label' => 'Ordner A', 'href' => '/downloads/ordner-a'],
    ['content' => '<img src="..."> ', 'label' => 'Ordner B', 'href' => '/downloads/ordner-b'],
];
echo Ui::grid($items, ['cols' => 4]);

// Spacing-Utilities (für <hr>, Abstände etc.)
// Klassen: esse-mt-sm/md/lg/xl, esse-mb-sm/md/lg/xl, esse-my-sm/md/lg
// → <hr class="esse-my-lg">  statt  <hr class="mt-4 mb-4">

// Submit-Button innerhalb eines bestehenden Formulars
// (kein eigenes <form> — nur der Button)
echo Ui::button('Hochladen', '#', ['type' => 'submit', 'icon' => 'upload']);
echo Ui::button('Ordner erstellen', '#', ['type' => 'submit', 'variant' => 'secondary']);

// Icons (pack-agnostisch — Prefix kommt vom aktiven Icon-Pack)
echo Ui::icon('house');          // → <i class="bi bi-house"></i> (Bootstrap Icons default)
echo Ui::icon('images');         // → <i class="bi bi-images"></i>
```

### Verfügbare Methoden

| Methode | Parameter | Beschreibung |
|---|---|---|
| `Ui::panel()` | title, content, opts | Container mit Header/Body/Footer |
| `Ui::button()` | label, url, opts | Link oder POST-Formular-Button |
| `Ui::alert()` | type, message, opts | Hinweis-/Fehlermeldung |
| `Ui::badge()` | label, type | Kleines Status-Label |
| `Ui::grid()` | items[], opts | Responsives Grid (nutzt esse-grid) |
| `Ui::emptyState()` | title, message, opts | Leerer Zustand mit CTA |
| `Ui::section()` | title, content, opts | Abschnitt mit Titel + optionaler Aktion |
| `Ui::table()` | headers[], rows[], opts | Datentabelle |
| `Ui::tabs()` | tabs[], opts | Tab-Navigation |
| `Ui::divider()` | opts | Trennlinie mit optionalem Spacing/Label |
| `Ui::breadcrumb()` | items[] | Navigations-Breadcrumb |
| `Ui::icon()` | name, fallback, opts | Icon mit Farb- und Größen-Option |

### variant-Werte
`'default'` · `'success'` · `'warning'` · `'danger'` · `'info'`

### Theme-Override via Hook

Themes können jede Komponente überschreiben:
```php
// In Theme::boot():
\Esse\Hooks::on('ui.panel', function(string $defaultHtml, array $props): string {
    // Eigenes HTML rendern
    return '<article class="my-card">...</article>';
});
```

### Rückwärtskompatibilität

Bestehende Plugins die noch Bootstrap-Klassen nutzen **brechen nicht** — sofern das aktive
Theme Bootstrap lädt. Der Wechsel auf `Ui::*` ist empfohlen aber nicht erzwungen.

## Theme-agnostische Ausgabe

Plugins dürfen **keine** Framework-spezifischen Klassen direkt ausgeben (z.B. Bootstrap `container`, `row`, `col-*`). Das macht das Plugin abhängig vom aktiven Theme.

Stattdessen: ESSE-Standard-Grid-Klassen nutzen, die jedes Theme implementiert.

### esse-grid

```html
<!-- FALSCH — Bootstrap-abhängig: -->
<div class="container">
    <div class="row g-4">
        <div class="col-6 col-sm-4 col-md-3">...</div>
    </div>
</div>

<!-- RICHTIG — Theme-agnostisch: -->
<div class="esse-grid-wrap">
    <div class="esse-grid" data-cols="4">
        <div class="esse-grid-item">...</div>
        <div class="esse-grid-item">...</div>
    </div>
</div>
```

**Verfügbare Klassen:**

| Klasse | Bedeutung |
|---|---|
| `esse-grid-wrap` | Äußerer Container (volle Breite) |
| `esse-grid` | Grid-Container |
| `esse-grid-item` | Einzelnes Element |
| `data-cols="2\|3\|4\|6"` | Spaltenanzahl (Theme entscheidet Breakpoints) |

Alle Themes müssen diese Klassen implementieren. esse-base nutzt Flexbox, esse-cyber nutzt CSS Grid — das Plugin merkt davon nichts.

### Plugin-eigene Styles

Bootstrap-Hilfsklassen (`fw-semibold`, `text-truncate`, `badge`, `py-4` etc.) durch eigene CSS-Klassen ersetzen:

```php
// Template einbinden (self-hosted via Route):
Router::get('/plugins/esse-gallery/assets/{file}', function(string $file) {
    $path = $this->basePath('assets/' . basename($file));
    if (!file_exists($path)) { http_response_code(404); exit; }
    header('Content-Type: ' . (mime_content_type($path) ?: 'text/css'));
    readfile($path);
}, ['auth' => 'public']);
```

```html
<!-- Im Frontend-Template: -->
<link rel="stylesheet" href="/plugins/esse-gallery/assets/css/gallery.css">
```

```css
/* plugins/esse-gallery/assets/css/gallery.css */
.gal-card { text-decoration: none; color: inherit; display: block; }
.gal-thumb { aspect-ratio: 1/1; overflow: hidden; background: #111; }
.gal-thumb img { width: 100%; height: 100%; object-fit: cover; transition: transform .3s; }
.gal-card:hover .gal-thumb img { transform: scale(1.05); }
.gal-label { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.gal-badge { position: absolute; bottom: .5rem; right: .5rem; font-size: .75rem; }
```

---

## Plugin veröffentlichen (GitHub-Repo)

Damit ein Plugin im ESSE Plugin-Browser erscheint und installierbar ist:

**1. GitHub-Repo anlegen**
```bash
gh repo create nfsmw15/esse-mein-plugin --public \
  --description "Kurzbeschreibung"
```

**2. Topic `esse-plugin` hinzufügen**
```bash
gh repo edit nfsmw15/esse-mein-plugin --add-topic esse-plugin
```
Ohne dieses Topic erscheint das Plugin **nicht** im Browser.

**3. Release erstellen** (nötig für "Installieren"-Button)
```bash
gh release create v1.0.0 \
  --repo nfsmw15/esse-mein-plugin \
  --title "esse-mein-plugin v1.0.0" \
  --notes "Initial release"
```
GitHub erstellt automatisch einen Source-ZIP — keine manuelle ZIP-Erstellung nötig.
Die Version in `plugin.json` muss mit dem Release-Tag übereinstimmen (ohne `v`-Prefix).

**Update-Mechanismus:**
- ESSE vergleicht `version` aus `plugin.json` mit dem neuesten GitHub-Release-Tag
- Wenn Release-Version neuer → "Update"-Button erscheint
- `version_compare()` wird intern verwendet → Semantic Versioning beachten (`1.0.0` < `1.1.0` < `2.0.0`)

**ZIP-Struktur die GitHub erstellt:**
```
nfsmw15-esse-mein-plugin-abc123/   ← Root-Ordner (wird automatisch erkannt und entfernt)
├── plugin.json
├── Plugin.php
└── ...
```
ESSE strippt den Root-Ordner automatisch beim Installieren.

---

## README-Vorlage

Damit alle Plugin-READMEs einheitlich aufgebaut sind, gilt folgende Abschnitts-Reihenfolge.
Nicht jeder Abschnitt passt zu jedem Plugin — die mit „falls zutreffend" markierten weglassen,
wenn sie nicht zutreffen.

1. Titel + 1-Zeiler-Beschreibung mit Link auf [ESSE CMS](https://github.com/nfsmw15/esse-cms)
2. Badges (siehe unten)
3. Über das Plugin — 2–4 Sätze; bei theme-agnostischer Ausgabe (`Esse\Ui`) das erwähnen
4. Voraussetzungen — ESSE-CMS-Version, PHP-Version, Extensions, externe Abhängigkeiten
5. Installation — Admin-Upload, manuell, optional „ZIP selbst erstellen"
6. Routen — Tabelle: Route | Beschreibung | Sichtbarkeit
7. Berechtigungen — Tabelle, *falls zutreffend* (eigene Permissions registriert)
8. Features — Liste
9. Konfiguration — *falls zutreffend* (Plugin-Settings vorhanden)
10. Datenbankstruktur — angelegte Tabellen, *falls zutreffend*
11. Dateistruktur — Verzeichnisbaum
12. Sicherheit — *falls zutreffend* (Datei-Up-/Downloads, sensible Daten)
13. Lizenz

### Badges

Direkt unter dem 1-Zeiler, als eigener Absatz. Drei Badges, zwei Pflegestrategien:

- **Release** — zieht die Version live über die GitHub-API (shields.io `github/v/release`).
  Entspricht immer dem neuesten Release-Tag — keine manuelle Pflege, keine Drift zur
  `plugin.json`-Version (die das CMS beim Update-Check ohnehin damit abgleicht).
- **License** und **ESSE CMS** — statisch, weil sie sich praktisch nie ändern
  (Lizenz nie, CMS-Mindestversion nur bei Breaking Changes).

> **Wichtig:** Der Wert im **ESSE CMS**-Badge sollte exakt `requires.esse` aus `plugin.json`
> entsprechen. Das ist keine reine Kosmetik — der Core prüft `requires.esse` beim Aktivieren
> aktiv gegen `ESSE_VERSION` und blockt die Aktivierung bei Inkompatibilität
> (`admin/plugins/index.php`). Weichen Badge und `plugin.json` voneinander ab, zeigt das
> README eine andere Mindestversion als das CMS tatsächlich durchsetzt.

```markdown
[![Release](https://img.shields.io/github/v/release/nfsmw15/esse-mein-plugin?label=release&color=blue)](https://github.com/nfsmw15/esse-mein-plugin/releases)
[![License](https://img.shields.io/badge/license-AGPL--3.0--or--later-green)](LICENSE)
[![ESSE CMS](https://img.shields.io/badge/esse--cms-%3E%3D0.1.0-orange)](https://github.com/nfsmw15/esse-cms)
```

Repo-Namen und Mindestversion an das jeweilige Plugin anpassen. Solange ein Plugin nur als
Pre-Release (z.B. `v0.x.x-alpha`) existiert, dem Release-Badge `&include_prereleases` anhängen,
sonst zeigt shields.io „no releases found" an.

---

## Checkliste neues Plugin

- [ ] `plugin.json` mit eindeutigem `name` (entspricht dem Verzeichnisnamen)
- [ ] `Plugin.php` mit korrektem Namespace (`class Plugin extends \Esse\Plugin`)
- [ ] DB-Migration in `boot()` mit `CREATE TABLE IF NOT EXISTS`
- [ ] `boot()` registriert Routen, `addAdminNav()`, `registerPage()`
- [ ] `activeNav` in Admin-Templates stimmt **exakt** mit `activeSlug` aus `addAdminNav()` überein
- [ ] `uninstall()` löscht alle Plugin-Daten (Tabellen, Einstellungen)
- [ ] Frontend-Templates haben keinen eigenen `<h1>` mit dem Seitentitel (Theme rendert ihn bereits)
- [ ] Keine Slug-Konflikte mit Kern-Routen: `/`, `/login`, `/profil`, `/registrieren`, `/abmelden`, `/install`, `/admin/*`
- [ ] CSRF in allen POST-Handlern: `Auth::verifyCsrf()`
- [ ] Berechtigungen prüfen: `Auth::meetsRole()` oder `Auth::can()`
- [ ] `README.md`, `CHANGELOG.md`, `LICENSE` vorhanden
- [ ] ZIP ohne `.git/`, `.vscode/`, `node_modules/` verpacken
