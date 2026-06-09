# Changelog

All notable changes to ESSE CMS will be documented in this file.

## [Unreleased]

### Added

- **Security Headers** (`core/SecurityHeaders.php`): zentrale Browser-Hardening-Header fuer alle Core-Responses — CSP mit Same-Origin-Policy fuer Skripte/Styles/Fonts/Fetches, `frame-ancestors 'self'`, `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin` und restriktive `Permissions-Policy`. `script-src` und `style-src` erlauben keine Inline-Skripte/-Styles mehr.
- **Zwei-Faktor-Authentifizierung (TOTP)** (`core/Totp.php`, `core/QrCode.php`, `core/TwoFactor.php`, `admin/verify-2fa.php`): Nutzer können im Profil (`/profil` → „Sicherheit") optional eine Authenticator-App als zweiten Faktor zum Passwort aktivieren (RFC 6238) — inkl. Einrichtungs-QR-Code, der von einem eigenen, reinen PHP-QR-Encoder (ISO 18004, Byte-Modus, Reed-Solomon-Fehlerkorrektur über GF(256), Masken-Auswahl per Penalty-Bewertung) als inline-SVG erzeugt wird — kein JS-Vendoring, kein CDN, ganz im Stil des selbst gehosteten CAPTCHA-Ansatzes. Dazu zehn bcrypt-gehashte Einmal-Backup-Codes als Fallback, falls die App verloren geht (werden nach Verwendung ungültig, lassen sich mit Passwort-Bestätigung neu generieren). Beim Login wird nach korrektem Passwort zusätzlich der TOTP- oder ein Backup-Code abgefragt (`/admin/verify-2fa`, eigenes Rate-Limiting analog zum Login: 5 Fehlversuche → 60 s Sperre). Deaktivieren erfordert ebenfalls eine erneute Passwort-Bestätigung.
- **Passkeys / WebAuthn** (`core/WebAuthn.php`, `vendor/webauthn/`, `public/assets/js/webauthn.js`): eigenständige, passwortlose Anmeldemethode (discoverable credentials/FIDO2) — bewusst kein zusätzlicher Faktor zum Passwort, sondern vollwertiger Ersatz für Passwort UND TOTP zugleich (Touch ID, Windows Hello oder ein Sicherheitsschlüssel genügen als alleiniger Nachweis). Über den Button „Mit Passkey anmelden" auf der Login-Seite identifiziert sich der Nutzer komplett ohne E-Mail-/Passwort-Eingabe; die Registrierung läuft über den Sicherheits-Bereich im Profil (mehrere Passkeys pro Konto möglich, mit Label, „zuletzt verwendet", Umbenennen/Entfernen). Die WebAuthn-Kryptografie (Attestation-/Assertion-Prüfung, CBOR-Decoding, Signaturzähler zur Klon-Erkennung) läuft über die manuell unter `vendor/webauthn/` abgelegte, dependency-freie Bibliothek `report-uri/passkeys-php` (MIT-Lizenz, Lizenzdatei beiliegend — gleiches Vorgehen wie bei `vendor/phpmailer/`); eine Eigenimplementierung der Signaturprüfung wäre angesichts des Sicherheitsrisikos durch Eigenbau-Kryptocode nicht vertretbar gewesen.
- **CAPTCHA-Schutz** (`core/Captcha.php`): leichtgewichtiger, selbst gehosteter Spam-Schutz für Registrierung und „Passwort vergessen" — Rechenaufgabe + verstecktes Honeypot-Feld + Mindest-Ausfüllzeit (3s). Bewusst kein Bild-CAPTCHA: moderne OCR/KI liest verzerrten Text ohnehin mühelos, der Sicherheitsgewinn wäre real null, der Accessibility-Nachteil aber konkret.
- **Konfigurierbarer Site-Slogan** (`site_slogan`-Setting in Admin → Einstellungen): optionaler Untertitel unter dem Seitennamen in Login und Admin-Sidebar — bleibt das Feld leer, wird nichts angezeigt.
- **Theme-Hook `auth.login.render`**: Themes können `/login` jetzt vollständig im eigenen Design rendern (z.B. um es ins Frontend-Layout statt ins Admin-Look einzubetten). Die zentrale Auth-Logik (CSRF, Rate-Limiting, `Auth::attempt()`, Redirect-Auflösung) bleibt unverändert in `admin/login.php` — Themes übernehmen ausschließlich das Rendering. `/admin/login` ignoriert den Hook bewusst und bleibt als Fail-Safe-Notausgang immer beim Standard-Formular, falls ein Theme defekt ist oder deaktiviert wird. Dokumentiert in THEME_GUIDE.md („Eigene Login-Seite gestalten").
- **Theme-Hooks `auth.forgot_password.render` / `auth.reset_password.render`**: analog zu `auth.login.render` können Themes jetzt auch „Passwort vergessen" (`/admin/forgot-password`) und „Neues Passwort setzen" (`/admin/reset-password`) im eigenen Design rendern. Anders als beim Login gibt es bewusst **keinen** Fail-Safe-Alias — diese Seiten sind weniger kritisch (Admins können Passwörter weiterhin manuell über die Benutzerverwaltung zurücksetzen, falls ein Theme das Rendering zerschießt). Dokumentiert in THEME_GUIDE.md.

### Changed

- **CSP-Haertung / Inline-JS entfernt**: Passkey-Login, Profil-Passkey-Registrierung, Rollen-Rechte-Toggles, Updater-SSE, Menue-Erstellung, Menueeditor, Seitenliste, Icon-Picker, esse-ui Tabs/Alerts und Admin-Bestaetigungsdialoge wurden von Inline-Skripten/Event-Attributen auf externe Assets unter `public/assets/js/` und JSON-Konfig-Bloecke umgestellt. `script-src 'self'` kommt dadurch ohne `unsafe-inline` aus.
- **Inline-CSS entfernt**: Admin-, Auth-, Installer- und `esse-base`-Styles wurden aus `<style>`-Bloecken und `style`-Attributen in statische CSS-Assets verschoben. `style-src 'self'` kommt dadurch ohne `unsafe-inline` aus.
- **Theme-Hook `auth.register.render`**: Themes können `/registrieren` jetzt vollständig im eigenen Design rendern. Die zentrale Registrierungslogik (CSRF, CAPTCHA/Honeypot, Passwortregeln, E-Mail-Eindeutigkeit, User-Erstellung) bleibt im Core.
- Admin-Login (`admin/login.php`), „Passwort vergessen"/„Neues Passwort setzen" (`admin/forgot-password.php`, `admin/reset-password.php`) und Admin-Sidebar (`admin/layout.php`) zeigen jetzt den konfigurierten `site_name`/`site_slogan` statt fest „ESSE CMS"/„forge your web." — wichtig für produktive Instanzen mit eigenem Markennamen. Der Installer (`install/index.php`) behält bewusst das ESSE-CMS-eigene Branding, da dort noch keine Site konfiguriert ist.
- Einstellungen: redundante Karte „Theme & Menüpositionen" entfernt (Themes sind direkt über die Admin-Navigation unter „Themes verwalten" erreichbar).
- **PLUGIN_GUIDE.md / THEME_GUIDE.md — README-Vorlage und Badges**: neuer Abschnitt „README-Vorlage" mit einheitlichem Gliederungsschema für Plugin- und Theme-READMEs sowie Badge-Konvention — Release-Badge zieht die Version live über die GitHub-API (immer aktuell, keine manuelle Pflege, keine Drift zur `plugin.json`/`theme.json`-Version), Lizenz- und CMS-Kompatibilitäts-Badge bleiben statisch, da sie sich praktisch nie ändern. Dazu der Hinweis, dass das „ESSE CMS"-Badge bei Plugins exakt `requires.esse` aus `plugin.json` entsprechen sollte (wird beim Aktivieren tatsächlich gegen `ESSE_VERSION` geprüft, siehe `admin/plugins/index.php`), bei Themes dagegen rein informativ ist (kein `requires`-Feld, keine Versionsprüfung im Core). README.md hat denselben Badge-Block (Release/Lizenz/PHP-Version) erhalten.
- **PLUGIN_GUIDE.md / THEME_GUIDE.md — Navigation und Checklisten**: Inhaltsverzeichnis mit Sprunglinks ergänzt (beide Guides sind mittlerweile über 1000 bzw. 500 Zeilen lang). Plugin-Checkliste um die bisher fehlenden Kern-Routen `/` und `/login` in der Slug-Konflikt-Liste erweitert. Theme-Checkliste um einen Hinweis auf die `auth.login.render`-Hooks ergänzt (inkl. Pflichtfeld `name="_form" value="admin_login"`) sowie um `CHANGELOG.md`/`LICENSE` — die Theme-Grundstruktur und -Checkliste nannten bisher nur `README.md`, obwohl beide bestehenden Theme-Repos (esse-dashboard, esse-cyber) auch CHANGELOG und LICENSE mitbringen und die Plugin-Guide alle drei bereits listete.

### Fixed

- Security-Migrationen fuer TOTP/Passkeys laufen jetzt bereits beim Boot, auch ohne eingeloggten Nutzer. Dadurch werden `totp_*`-Spalten und `webauthn_credentials` rechtzeitig angelegt, bevor Login-, Passkey- oder Profil-Flows darauf zugreifen.
- Session-Cookie-Hardening: `PHPSESSID` bekommt auf HTTPS-Installationen jetzt zuverlaessig das `Secure`-Flag, auch wenn PHP hinter Hosting-/Proxy-Setups `$_SERVER['HTTPS']` nicht setzt, solange `ESSE_URL` mit `https://` konfiguriert ist.
- CHANGELOG: veralteter Hinweis „Repo-based plugin/theme install (apt-style) not yet implemented" aus „Known Issues / Alpha Limitations" entfernt — das Feature ist bereits seit v0.1.1-alpha implementiert und dort dokumentiert.

## [0.1.8-alpha] - 2026-06-07

### Added

- **Rollenbasierte Seitenzugangs-Steuerung**: Neue Sichtbarkeitsoptionen `public`, `guest_only`, `registered`, `roles` ersetzen das bisherige `public/members/admin`-System.
- **`PageVisibility`-Klasse** (`core/PageVisibility.php`): zentrale Hilfsfunktionen für get/check/save der Sichtbarkeit sowie für Icon-Overrides.
- **`esse_page_roles`-Tabelle**: ordnet Seiten bestimmten Rollen zu (wenn Sichtbarkeit = `roles`).
- **`esse_page_visibility`-Tabelle**: speichert Sichtbarkeits- und Icon-Overrides für Plugin- und Standardseiten (inkl. neuer `icon`-Spalte).
- **Admin → Seiten**: komplett überarbeitet — eine einheitliche Tabelle für CMS-, Plugin- und Standardseiten mit Abschnitts-Trennzeilen, sodass gleichartige Spalten (Titel, Slug, Sichtbarkeit, Verwendung, Status) untereinander ausgerichtet sind. Sichtbarkeit, Seitenzuordnung (Startseite, Startseite nach Login, Logout-, Fehlerseite) und Icon sind direkt als klickbare Badges inline editierbar (Modal + AJAX, kein Neuladen).
- **Standardseiten editierbar**: Sichtbarkeit von `/profil` und `/registrieren` im Admin überschreibbar.
- **Icon-Anzeige**: Zugewiesene Icons werden jetzt in `/admin/pages` und `/admin/menus/edit/` direkt angezeigt — auf einen Blick erkennbar, ob/welches Icon eine Seite oder ein Menüpunkt hat.
- **Icon-Override für Plugin-/Standardseiten**: Admins können Icons für Plugin- und Standardseiten setzen oder überschreiben, ohne den Plugin-Code anzufassen — auch wenn das Plugin selbst kein Icon mitbringt.
- **Login-abhängige Startseite**: `/` löst jetzt dynamisch auf — nicht eingeloggte Besucher landen auf der konfigurierten `homepage_slug`, eingeloggte auf `login_homepage_slug` (mit Fallback auf die allgemeine Startseite). Betrifft auch alle "Zur Website"-Links im Admin-Menü und den Startseite-Button auf der Fehlerseite, da diese auf `/` zeigen.
- **LICENSE**-Datei (AGPL-3.0) ergänzt — war in README/PLUGIN_GUIDE bereits referenziert, fehlte aber im Repo.

### Changed

- **Updater**: README.md, CHANGELOG.md, PLUGIN_GUIDE.md und THEME_GUIDE.md werden bei In-App-Updates nicht mehr auf bestehenden Instanzen überschrieben/neu erzeugt (bleiben aber Teil des Release-ZIPs für Neuinstallationen). LICENSE bleibt bewusst ausgenommen und wird weiterhin aktuell gehalten.
- Admin → Seiten-Formular: Sichtbarkeits-Dropdown auf neue Werte umgestellt, mit Rollen-Checkboxen wenn `roles` gewählt.
- `PageRenderer::renderFile()`: prüft Sichtbarkeit jetzt automatisch über die Override-Tabelle (Plugins und Standardseiten).
- `Menu::isVisible()`: nutzt `PageVisibility` für alle Seitentypen (CMS, Plugin, Standard).
- `/profil`-Route: hardcoded Auth-Check entfernt — Sichtbarkeit über `PageVisibility` gesteuert (Standard: `registered`).
- `/registrieren`-Route: Standard-Sichtbarkeit `guest_only`.
- Installer: `esse_pages.visibility` von `ENUM` auf `VARCHAR(20)` geändert.

### Fixed

- **`/`-Route**: Die Entscheidung „Seite direkt rendern oder weiterleiten" basiert jetzt darauf, ob tatsächlich eine veröffentlichte CMS-Seite mit dem konfigurierten Slug existiert — vorher führte ein als Startseite konfigurierter Standard- oder Plugin-Slug ohne führenden Slash (z. B. `login`) zu einem 404, weil versucht wurde, ihn als CMS-Seite zu rendern.
- **Slug-Sanitisierung** in den Badge-Speicher-Endpunkten (`save_visibility`, `save_page_target`, `save_page_icon`) entfernte versehentlich `/` aus Slugs und zerstörte dadurch mehrteilige Plugin-Pfade wie `mumble/dashboard` (z. B. zu `mumbledashboard`).
- Icon-Anzeige für Plugin-Seiten: fehlerhaftes Entfernen des `bi-`-Präfixes per `ltrim()` (entfernte einzelne passende Zeichen statt des Präfixes) mangelte Icon-Namen wie `bi-info-circle` zu `nfo-circle` — durch `PageVisibility::stripIconPrefix()` mit korrektem Pattern ersetzt.

### Migration

- Bestehende Seiten mit `visibility = 'members'` werden automatisch auf `registered` migriert.
- Bestehende Seiten mit `visibility = 'admin'` werden auf `roles` migriert; `admin`-Rolle wird in `esse_page_roles` eingetragen.
- `esse_page_visibility`: neue `icon`-Spalte wird bei bestehenden Installationen automatisch per `ALTER TABLE` ergänzt.

---

## [0.1.7-alpha] - 2026-06-05

### Added

- **Admin → Einstellungen → Seitenzuordnung**: Startseite, Startseite nach Login, Logoutseite und Fehlerseite können zentral gewählt werden.
- **Standardseiten in Seitenauswahlen**: Loginseite, Registrierungsseite und Profilseite erscheinen neben CMS- und Plugin-Seiten in Einstellungen und Menü-Editor.
- **`PageTargets`**: gemeinsame Hilfsklasse für auswählbare Seitenziele und sichere interne Redirect-URLs.

### Changed

- Login ohne konkreten Redirect führt nun auf die konfigurierte Startseite nach Login.
- Frontend- und Admin-Logout verwenden die konfigurierte Logoutseite.
- Die globale Startseite kann nun auch auf Standard- oder Plugin-Seiten zeigen.

### Fixed

- Menü-URLs für Standardseiten mit führendem Slash werden korrekt gerendert.
- Benutzerdefinierte Fehlerseiten verwenden nur veröffentlichte Standard-CMS-Seiten und respektieren Sichtbarkeit.

---

## [0.1.6-alpha] - 2026-06-05

### Added

- **Ui-Komponentenschicht** (`core/Ui.php`, `esse-ui.css`): Plugin-seitige Ausgabe über theme-agnostische `Ui::*`-Methoden statt Bootstrap-Klassen — Panel, Button, Alert, Badge, Grid, EmptyState, Section, Table, Tabs, Breadcrumb, Divider, Icon
- **Icon-Picker**: Suchmodal in Seiten-Formular und Menü-Editor mit statischer Deutsch→Englisch-Übersetzungstabelle (~130 Begriffe)
- **Icon-Pack-Verwaltung**: Admin → Icon-Packs — Packs per ZIP installieren, aktivieren, löschen; Standard `iconpack.json` mit `name`, `version`, `prefix`, `css`
- **`Ui::iconPackCssTag()` / `Ui::iconPackCssUrl()`**: Theme-seitige Helfer für `<link>`-Tag des aktiven Icon-Packs (esse-base nutzt diese)
- **Admin-Sidebar**: User-Dropdown mit Profil, "← Zur Website" und Abmelden direkt in der Sidebar
- **`PageRenderer::renderFile()`**: optionaler `$icon`-Parameter übergibt Icon an das Theme-`$page`-Array

### Changed

- `Ui::icon()` liest Prefix aus aktiver `iconpack.json` — Plugins übergeben nur den Icon-Namen (ohne Pack-Prefix), Theme und CSS-Klasse werden automatisch aufgelöst
- Plugin-Nav-Icons: Pack-Prefix wird beim Rendern automatisch entfernt (`bi-newspaper` → `newspaper`) — Rückwärtskompatibilität
- Seiten-Icons in esse-base über `Ui::icon()` gerendert (pack-agnostisch); volle CSS-Klassen weiterhin unterstützt

### Fixed

- esse-base: Login-Dropdown bleibt bei Fehler geöffnet und öffnet sich automatisch wenn ein `login_error` vorhanden ist
- Admin-Login: Fehler werden inline auf der Login-Seite angezeigt statt zur Homepage umzuleiten
- `admin/layout.php`: fehlendes `endif` beim `manage_themes`-Block
- esse-base: `.esse-content a` überschrieb Textfarbe von `.esse-btn--primary` (blauer Text auf blauem Hintergrund)
- esse-base: `.esse-table tbody tr:hover` zeigte weißen Hintergrund statt dunklem Hover-Ton

---

## [0.1.5-alpha] - 2026-06-04

### Added

- **Admin → Rollen & Rechte**: neue Verwaltungsseite für Rollen und Permissions
- **Benutzer-Formular**: Per-User Permission Overrides — zusätzliche Rechte unabhängig von der Rolle vergeben
- **`php_upload` sichtbar**: erscheint in Benutzer-Permissions mit "Gefährlich"-Badge
  - Alle Standard-Rollen (member, author, editor, admin) als Übersicht mit zugewiesenen Rechten
  - Eigene Rollen anlegen und löschen
  - Permissions per Checkbox für eigene Rollen konfigurierbar
  - Standard-Rollen sind read-only (werden durch `Auth::DEFAULT_ROLE_PERMISSIONS` verwaltet)
  - Nur Forge und Nutzer mit `manage_admins` haben Zugriff

### Changed

- `role`-Spalte in `esse_users` von `ENUM` auf `VARCHAR(50)` — Custom-Rollen können jetzt zugewiesen werden
- `manage_admins` zum Admin-Standard-Rechte-Set hinzugefügt
- Admin routes now use granular permissions instead of broad `admin` role checks
- Admin sidebar only shows sections the current user is allowed to access
- Default role permissions are centralized in `Auth` and synchronized for existing installations
- Installer now seeds default roles and role-permission grants from the shared permission matrix

### Security

- Editor uploads require `manage_files` or `manage_content` instead of relying on role hierarchy
- Admin role assignment now requires `manage_admins`; Forge remains required for Forge accounts
- Assigning custom roles and per-user permission overrides now requires `manage_admins`
- Users can only edit accounts whose current role they are allowed to manage
- Custom roles cannot be deleted while they are still assigned to users

---

## [0.1.4-alpha] - 2026-06-03

### Security

- Editor image uploads now require CSRF validation and verify uploaded files as real images
- Public upload directory now blocks PHP/PHAR execution via `.htaccess`
- PHP page rendering and deletion now constrain stored file paths to basenames inside `pages/`
- Login and password reset forms now have lightweight session throttling

### Fixed

- Update, plugin, and theme checks now create `storage/cache` automatically when missing

---

## [0.1.3-alpha] - 2026-06-03

### Fixed

- System updater release checks now use the shared GitHub API client, so configured GitHub tokens apply to update detection
- System updater and plugin/theme discovery now use the same GitHub request headers and API version

---

## [0.1.2-alpha] - 2026-06-03

### Fixed

- Updater SSE progress no longer calls an undefined `$log` callback when updating `ESSE_VERSION` in `local.php`
- Update "Erneut prüfen" now clears the update-check cache via CSRF-protected POST
- Fallback `ESSE_VERSION` updated to `0.1.2-alpha` so GitHub release checks can detect this patch release

---

## [0.1.1-alpha] - 2026-06-03

### Added

**Plugin & Theme Repository System**
- GitHub-based discovery via `esse-plugin` / `esse-theme` topics
- Plugin browser (Admin → Plugins → "Verfügbar") with install/update from GitHub releases
- Theme browser (Admin → Themes → "Verfügbar")
- Version comparison — update badge when newer release available
- Configurable repo channels with trust levels (official/community)
- Optional GitHub API token (encrypted) for higher rate limits (60 → 5000 req/h)
- Plugin CMS version compatibility check on activation (`requires.esse` field)

**esse-grid Standard**
- Theme-agnostic grid classes (`esse-grid`, `esse-grid-item`, `data-cols`) implemented in esse-base and esse-cyber
- Plugins should use esse-grid instead of Bootstrap-specific classes

**Documentation**
- `PLUGIN_GUIDE.md` expanded: autoloading, constants, settings API, CSRF/AJAX, icon fields, esse-grid, publishing
- `THEME_GUIDE.md` added: full theme development reference including esse-grid requirement, template variables, login templates, publishing

**Backup & Update**
- Version included in backup filename (`pre-update_v0.1.0-alpha_2026-06-01.zip`)
- Manual backup creation, secure download, restore function
- Pre-release update channel toggle with warning
- Updater shows "Seite neu laden" button instead of auto-reload

**UI / UX**
- Icon field for pages and menu items (CSS class, works with any icon pack)
- Menu items: enable/disable toggle (cascades to children)
- Admin sidebar: "← Zur Website" link
- Login page: footer menu from active theme settings
- Login autocomplete fixed (`username` instead of `email` — prevents address autofill)
- Plugin and theme pages show in menu dropdown grouped by type

### Changed

- esse-dashboard and esse-cyber themes moved to separate repos (`nfsmw15/esse-dashboard`, `nfsmw15/esse-cyber`)
- esse-cyber now bundles Bootstrap for plugin grid support
- ESSE_VERSION moved to `local.php`-overridable constant

### Security

- **ZIP installer**: slug validated against `^[a-z0-9][a-z0-9-]{1,63}$`, realpath check against path traversal
- **Updater SSE**: GET `/admin/update/run` now requires a CSRF-protected one-time session token
- **File upload**: SVG removed from allowed types (XSS risk); MIME check applies to all types
- **Login**: `sanitizeRedirect()` applied at all redirect points (previously only at POST success)
- **Plugin/Theme install**: explicit `manage_plugins` / `manage_themes` permission check added
- **Repo downloads**: `CURLOPT_FAILONERROR`, HTTP status check, ZIP signature (`PK`) validation
- **Side-effect actions**: Test-Mail and cache-refresh moved from GET to CSRF-protected POST

### Fixed

- Double flash messages in admin (PRG pattern applied consistently)
- Summernote dropdown menus (Bootstrap 5.3 compatibility shim)
- Menu drag & drop timing issue (SortableJS loaded before init code)
- Dashboard theme footer links not opening (visibility check logic)
- Footer links showing blue (global `a` color reset in esse-cyber)

---

## [0.1.0-alpha] - 2026-06-01

Initial alpha release. Core systems are functional.

### Added

**Core**
- PHP 8.1+ framework with code-based routing (no URL table in database)
- Hook/event system (`Hooks::fire`, `Hooks::filter`, `Hooks::on`)
- Service container (`Container::singleton`, `Container::bind`)
- PDO database wrapper with prepared statements
- AES-256-CBC encryption for sensitive settings (SMTP password)
- PHPMailer 7.x integration for SMTP email

**Installer**
- Web-based installer: database setup, site config, Forge account creation
- Optional private path: store config/storage outside webroot
- Auto-generated encryption key

**Authentication & Roles**
- Session-based auth with bcrypt password hashing
- Role hierarchy: Forge → Admin → Editor → Author → Member → Guest
- Granular permissions (e.g. `php_upload` separate from admin role)
- Custom roles with selectable permissions
- Password reset via email token (1h expiry)
- CSRF protection on all forms
- Navbar dropdown login (inline, no page redirect)

**Admin Panel**
- Dark Bootstrap 5 sidebar layout
- Dashboard with page stats
- Page management: create, edit, delete, PHP/HTML upload
- Menu management: named menus, sub-items, drag & drop reorder, indent/dedent
- User management: create, edit, activate/deactivate, role assignment
- Plugin management: ZIP install/update/uninstall, enable/disable
- Theme management: ZIP install, activate, menu position assignment
- Settings: site name, URL, homepage, SMTP, registration toggle
- Summernote WYSIWYG editor with image upload

**Frontend**
- Direct slug routing (`/about` not `/page/about`)
- Page visibility: public / members-only / admin-only
- Profile page (`/profil`): change display name, email, password
- Registration page (`/registrieren`, optional, admin-controlled)
- Frontend logout (`/abmelden`)
- Theme-aware 404/403 error pages with navigation

**Plugin System**
- Plugin base class: `boot()`, `install()`, `uninstall()`
- `addAdminNav()`: register sidebar entries
- `registerPage()`: register frontend pages (visible in pages list + menu dropdown)
- `admin.nav` hook for sidebar integration
- Plugin pages respected in menu visibility and slug conflict detection

**esse-base Theme**
- Bootstrap 5 dark navbar with dropdown menus
- Split dropdown: parent link navigates, arrow opens sub-menu
- Inline navbar login dropdown
- User menu: profile, admin link, logout
- Theme-aware footer with column groups and header labels
- Menu visibility filtering (members-only, admin-only pages hidden)
- Error page template (404/403)
- Configurable menu positions via theme.json

**System Updater**
- GitHub release check (cached 1h)
- SSE live terminal output during update
- Automatic backup before update (files + DB dump)
- Protected paths: config, local.php, storage, install/installed.lock

**Security**
- All sensitive directories blocked via `Require all denied` (.htaccess)
- Optional private path: config/storage outside webroot
- PHP upload as explicit permission, not automatic for admins
- Forge account promotion shows risk dialog
- Redirect sanitization on login

### Known Issues / Alpha Limitations

- Summernote editor: minor tooltip warnings in browser console (cosmetic, does not affect functionality)
- Menu drag & drop: same-level reorder works; cross-level requires indent/dedent buttons
- File manager (browse existing uploads) not yet implemented
- esse-download, esse-gallery and other plugins not yet ported
