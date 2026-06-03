# ESSE CMS — Plugin-Entwicklung

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
    'bi-newspaper',   // Bootstrap Icons Klasse
    'admin.news'      // activeSlug — MUSS mit $activeNav in den Admin-Templates übereinstimmen!
);
```

> **Häufiger Fehler:** Wenn der Sidebar-Link nicht aktiv hervorgehoben wird, stimmt
> der `activeSlug` nicht mit `$activeNav` im Template überein.

### Frontend-Seiten beim CMS anmelden

Damit die Seite in der Seiten-Liste erscheint, im Menü-Dropdown auswählbar ist
und Slug-Konflikte erkannt werden:

```php
$this->registerPage('/news',      'News-Liste',  'bi-newspaper');
$this->registerPage('/news/{id}', 'News-Detail', 'bi-newspaper');
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

Seiten und Menü-Einträge haben ein optionales `icon`-Feld (volle CSS-Klasse):

```php
// Seite programmatisch mit Icon anlegen:
DB::insert(DB::table('pages'), [
    'slug'       => 'dashboard',
    'title'      => 'Dashboard',
    'icon'       => 'bi bi-speedometer2',  // optional
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
    'icon'       => 'bi bi-speedometer2',  // optional
    'page_slug'  => 'dashboard',
    'sort_order' => 10,
    'active'     => 1,
]);
```

Themes rendern das Icon automatisch vor Label/Titel wenn `icon` gesetzt ist.
Funktioniert mit allen selbst gehosteten Icon-Packs — einfach die volle CSS-Klasse angeben.

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

        $this->addAdminNav('News', '/admin/news', 'bi-newspaper', 'admin.news');
        $this->registerPage('/news',      'News',        'bi-newspaper');
        $this->registerPage('/news/{id}', 'News-Detail', 'bi-newspaper');

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

## Checkliste neues Plugin

- [ ] `plugin.json` mit eindeutigem `name` (entspricht dem Verzeichnisnamen)
- [ ] `Plugin.php` mit korrektem Namespace (`class Plugin extends \Esse\Plugin`)
- [ ] DB-Migration in `boot()` mit `CREATE TABLE IF NOT EXISTS`
- [ ] `boot()` registriert Routen, `addAdminNav()`, `registerPage()`
- [ ] `activeNav` in Admin-Templates stimmt **exakt** mit `activeSlug` aus `addAdminNav()` überein
- [ ] `uninstall()` löscht alle Plugin-Daten (Tabellen, Einstellungen)
- [ ] Frontend-Templates haben keinen eigenen `<h1>` mit dem Seitentitel (Theme rendert ihn bereits)
- [ ] Keine Slug-Konflikte mit Kern-Routen: `/admin/*`, `/install`, `/profil`, `/registrieren`, `/abmelden`
- [ ] CSRF in allen POST-Handlern: `Auth::verifyCsrf()`
- [ ] Berechtigungen prüfen: `Auth::meetsRole()` oder `Auth::can()`
- [ ] `README.md`, `CHANGELOG.md`, `LICENSE` vorhanden
- [ ] ZIP ohne `.git/`, `.vscode/`, `node_modules/` verpacken
