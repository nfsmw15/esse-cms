# ESSE CMS — Plugin-Entwicklung

## Grundstruktur

Ein Plugin besteht aus mindestens diesen Dateien:

```
plugins/mein-plugin/
├── plugin.json       ← Pflicht: Metadaten
├── Plugin.php        ← Pflicht: Hauptklasse
└── ...               ← eigene PHP-Dateien, Templates, Assets
```

---

## plugin.json

```json
{
    "name": "mein-plugin",
    "version": "1.0.0",
    "description": "Kurzbeschreibung des Plugins.",
    "author": "Dein Name",
    "class": "MeinPlugin\\Plugin",
    "requires": {
        "esse": ">=0.1.0"
    }
}
```

**Wichtig:** `name` muss dem Verzeichnisnamen entsprechen (`plugins/mein-plugin/`).

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
        // Hier Routen, Hooks und Admin-Navigation registrieren.
    }

    public function install(): void
    {
        // Einmalig beim Installieren (z.B. DB-Tabellen anlegen)
    }

    public function uninstall(): void
    {
        // Einmalig beim Deinstallieren (z.B. DB-Tabellen löschen)
    }
}
```

---

## Verfügbare Methoden in boot()

### Routen registrieren

```php
// Frontend-Route
Router::get('/news', fn() => require $this->basePath('frontend/list.php'),
    ['name' => 'news.list', 'auth' => 'public']);

// Admin-Route (nur für Admins)
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

**auth-Werte:** `public`, `member`, `author`, `editor`, `admin`, `forge`, oder ein Permission-Slug wie `php_upload`

### Admin-Sidebar-Eintrag

```php
$this->addAdminNav(
    'News',           // Label in der Sidebar
    '/admin/news',    // URL
    'bi-newspaper',   // Bootstrap Icons Klasse
    'admin.news'      // activeNav-Wert (für aktiven Zustand)
);
```

### Frontend-Seiten beim CMS anmelden

Damit die Seite in der Seiten-Liste erscheint, im Menü auswählbar ist
und Slug-Konflikte erkannt werden:

```php
$this->registerPage('/news',      'News-Liste',  'bi-newspaper');
$this->registerPage('/news/{id}', 'News-Detail', 'bi-newspaper');
```

### Hooks verwenden

```php
// Eigenen Hook-Listener registrieren
$this->on('page.render', function(array $page, string $content) {
    // z.B. Inhalte vor dem Rendern modifizieren
});

// Oder direkt:
\Esse\Hooks::on('my.event', fn() => ...);
```

---

## Datenbankzugriff

```php
use Esse\DB;

// Tabellen-Namen mit Prefix (wichtig!)
$table = DB::table('news');  // → 'esse_news'

// Abfragen
$items = DB::fetchAll("SELECT * FROM `{$table}` ORDER BY created_at DESC");
$item  = DB::fetch("SELECT * FROM `{$table}` WHERE id = ?", [$id]);
$count = DB::value("SELECT COUNT(*) FROM `{$table}`");

// Schreiben
$id = DB::insert($table, ['title' => 'Titel', 'content' => '...']);
DB::update($table, ['title' => 'Neu'], ['id' => $id]);
DB::delete($table, ['id' => $id]);

// Rohe Query (für CREATE TABLE etc.)
DB::query("CREATE TABLE IF NOT EXISTS `{$table}` (...) ENGINE=InnoDB");
```

## Aktuellen User abfragen

```php
use Esse\Auth;

Auth::check();              // bool: eingeloggt?
Auth::user();               // array: User-Daten
Auth::id();                 // int: User-ID
Auth::role();               // string: 'forge', 'admin', ...
Auth::meetsRole('editor');  // bool: mindestens Editor?
Auth::can('php_upload');    // bool: hat Permission?
Auth::csrfToken();          // string: CSRF-Token für Formulare
Auth::verifyCsrf();         // bool: CSRF prüfen (in POST-Handlern)
```

---

## Plugin-Assets

Der `plugins/`-Ordner ist per `.htaccess` gesperrt — Dateien darin sind nicht direkt per HTTP erreichbar. Für öffentliche Assets (CSS, JS, Bilder) gibt es zwei Wege:

**Option A: Via Route ausliefern**
```php
Router::get('/plugins/mein-plugin/assets/{file}', function(string $file) {
    $path = $this->basePath('assets/' . basename($file));
    if (!file_exists($path)) { http_response_code(404); exit; }
    // mime type + readfile($path);
}, ['auth' => 'public']);
```

**Option B: In `public/vendor/` ablegen** (bei der Installation kopieren)
```php
public function install(): void
{
    $src  = $this->basePath('assets/');
    $dest = ESSE_ROOT . '/public/vendor/mein-plugin/';
    // copy files...
}
```

URL im Plugin:
```php
$this->assetUrl('css/style.css')
// → https://example.com/plugins/mein-plugin/public/css/style.css
// (funktioniert nur wenn Assets über Route ausgeliefert werden)
```

---

## E-Mail senden

```php
use Esse\Mailer;

// HTML-E-Mail (SMTP muss in Einstellungen konfiguriert sein)
Mailer::send(
    'empfaenger@example.com',
    'Max Mustermann',
    'Betreff',
    '<p>HTML-Inhalt</p>'
);

// Plain-Text
Mailer::send('to@example.com', 'Name', 'Betreff', 'Nur Text', false);
```

Wenn SMTP nicht konfiguriert ist, wirft `send()` eine `\RuntimeException`.

---

## Sensible Daten verschlüsseln

Für Plugin-Einstellungen die sensible Werte enthalten (API-Keys, Passwörter):

```php
use Esse\Crypto;
use Esse\DB;

// Speichern (verschlüsselt)
$ts = DB::table('settings');
DB::query("INSERT INTO `{$ts}` (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
    ['mein_plugin_api_key', Crypto::encrypt($apiKey)]
);

// Lesen (entschlüsselt)
$encrypted = DB::value("SELECT `value` FROM `{$ts}` WHERE `key`='mein_plugin_api_key'");
$apiKey    = Crypto::decrypt($encrypted ?? '');
```

---

## Flash-Messages in Admin-Seiten

```php
// Setzen (vor einem Redirect)
$_SESSION['flash'] = ['type' => 'success', 'message' => 'Gespeichert.'];
header('Location: /admin/mein-plugin');
exit;

// Lesen (am Anfang der Seite, nach dem Redirect)
$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
// Das Admin-Layout rendert $flash automatisch wenn es gesetzt ist.
```

`type` kann sein: `success`, `danger`, `warning`, `info`

---

## Admin-Templates mit Layout

Admin-Seiten des Plugins können das Admin-Layout von Esse nutzen:

```php
// plugins/mein-plugin/admin/list.php

use Esse\Auth;
use Esse\DB;

$pageTitle = 'News';
$activeNav = 'admin.news';  // muss mit addAdminNav() übereinstimmen

ob_start();
?>
<h1>Meine Plugin-Seite</h1>
<?php
$content = ob_get_clean();
require ESSE_ROOT . '/admin/layout.php';
```

---

## Komplettes Beispiel

`plugin.json`:
```json
{
    "name": "esse-news",
    "version": "1.0.0",
    "description": "News-System für ESSE CMS.",
    "author": "Andreas",
    "class": "EsseNews\\Plugin",
    "requires": { "esse": ">=0.1.0" }
}
```

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
        NewsRepository::migrate();

        // Sidebar-Eintrag
        $this->addAdminNav('News', '/admin/news', 'bi-newspaper', 'admin.news');

        // Seiten beim CMS anmelden
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

    public function install(): void   { NewsRepository::migrate(); }
    public function uninstall(): void { NewsRepository::drop(); }
}
```

---

## Checkliste neues Plugin

- [ ] `plugin.json` mit eindeutigem `name` (entspricht dem Verzeichnisnamen)
- [ ] `Plugin.php` mit korrektem Namespace (`class Plugin extends \Esse\Plugin`)
- [ ] `boot()` registriert Routen, addAdminNav(), registerPage()
- [ ] `install()` legt DB-Tabellen an (mit `CREATE TABLE IF NOT EXISTS`)
- [ ] `uninstall()` löscht DB-Tabellen und Daten
- [ ] Keine Slug-Konflikte mit Kern-Routen:
      `/admin/*`, `/install`, `/profil`, `/registrieren`, `/abmelden`
- [ ] CSRF in allen POST-Handlern: `Auth::verifyCsrf()`
- [ ] Berechtigungen prüfen: `Auth::meetsRole()` oder `Auth::can()`
