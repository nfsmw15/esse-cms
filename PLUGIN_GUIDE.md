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
// Frontend-Route
Router::get('/news', fn() => require $this->basePath('frontend/list.php'),
    ['name' => 'news.list', 'auth' => 'public']);

// Admin-Route
Router::get('/admin/news', fn() => require $this->basePath('admin/list.php'),
    ['name' => 'admin.news', 'auth' => 'admin']);

// Route mit Parameter
Router::get('/news/{id}', function (string $id) {
    $newsId = (int) $id;
    require $this->basePath('frontend/detail.php');
}, ['name' => 'news.detail', 'auth' => 'public']);

// Route mit Permission
Router::post('/news/create', fn() => require $this->basePath('frontend/create.php'),
    ['name' => 'news.create', 'auth' => 'member']);
```

**auth-Werte:** `public`, `member`, `author`, `editor`, `admin`, `forge`,
oder ein Permission-Slug wie `php_upload`

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

## Plugin-Assets

Der `plugins/`-Ordner ist per `.htaccess` gesperrt. Für öffentliche Assets gibt es zwei Wege:

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

## Checkliste neues Plugin

- [ ] `plugin.json` mit eindeutigem `name` (entspricht dem Verzeichnisnamen)
- [ ] `Plugin.php` mit korrektem Namespace (`class Plugin extends \Esse\Plugin`)
- [ ] DB-Migration in `boot()` mit `CREATE TABLE IF NOT EXISTS`
- [ ] `boot()` registriert Routen, `addAdminNav()`, `registerPage()`
- [ ] `activeNav` in Admin-Templates stimmt **exakt** mit `activeSlug` aus `addAdminNav()` überein
- [ ] `uninstall()` löscht alle Plugin-Daten (Tabellen, Einstellungen)
- [ ] Keine Slug-Konflikte mit Kern-Routen: `/admin/*`, `/install`, `/profil`, `/registrieren`, `/abmelden`
- [ ] CSRF in allen POST-Handlern: `Auth::verifyCsrf()`
- [ ] Berechtigungen prüfen: `Auth::meetsRole()` oder `Auth::can()`
- [ ] `README.md`, `CHANGELOG.md`, `LICENSE` vorhanden
- [ ] ZIP ohne `.git/`, `.vscode/`, `node_modules/` verpacken
