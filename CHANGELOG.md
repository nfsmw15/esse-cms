# Changelog

All notable changes to ESSE CMS will be documented in this file.

## [Unreleased]

### Fixed

- **`admin/.htaccess` sperrte den gesamten Adminbereich statt nur Direktzugriffe**: Die in 0.8.0-alpha eingeführte `admin/.htaccess` (`Require all denied`) wirkt auf Apache-Ebene pfadbasiert auf das ganze `admin/`-Verzeichnis — *bevor* die Rewrite-Regel der Root-`.htaccess` auf `index.php` greift. Da der komplette Adminbereich über erweiterungslose, geroutete URLs wie `/admin/login` läuft (nicht über die literalen `.php`-Dateien), sperrte das den gesamten Adminbereich aus (403 „Access Denied"), nicht nur Direktaufrufe wie `/admin/login.php`. Ersetzt durch ein auf `.php`/`.phtml`/etc. beschränktes `<FilesMatch>` (gleiches, bereits bewährtes Muster wie `public/uploads/.htaccess` und `themes/.htaccess`) — lässt geroutete URLs durch, blockt aber weiterhin literale Direktaufrufe.

## [0.8.0-alpha] - 2026-06-22

### Added

- **Eingebautes `[carousel]`-Widget**: Neue theme-unabhängige Komponente `\Esse\Ui::carousel()` (`core/Ui.php`, CSS in `public/vendor/esse-ui/esse-ui.css`, Navigation/Autoplay in `public/assets/js/esse-ui.js`) zeigt ausgewählte Mediathek-Bilder als Slideshow mit horizontalem Slide-Übergang — funktioniert in allen Themes ohne Bootstrap-JS-Abhängigkeit (reines CSS `transform`/Vanilla-JS). Registriert als Core-Shortcode `[carousel images="3,17,42" interval="5" height="md"]` (`core/CoreShortcodes.php`) mit wählbarer Höhe (Klein/Mittel/Groß/Volle Breite). Im „Widget einfügen"-Dialog gibt es dafür die neuen Attribut-Typen `'images'` und `'select'` (`public/assets/js/shortcode-picker.js`): ein Button öffnet wiederholt die Mediathek-Auswahl und sammelt Vorschau-Chips, ohne den bestehenden Mediathek-Picker zu verändern.
- **Gestapelte Bootstrap-Modals**: `public/assets/js/admin-common.js` hebt jetzt den z-index von Modal und Backdrop an, wenn ein Modal über einem bereits offenen weiteren geöffnet wird (z.B. Mediathek-Auswahl innerhalb des „Widget einfügen"-Dialogs) — vorher landete das innere Modal optisch dahinter.
- **Widgets im Editor bearbeitbar statt nur Rohtext**: Eingefügte Widgets erscheinen im Seiteneditor jetzt als klickbarer Vorschau-Baustein (Bild-Chips + Label, z.B. „6 Bilder · Höhe: Groß") statt als rohes `[tag attr="..."]`. Klick öffnet den „Widget einfügen"-Dialog erneut, vorausgefüllt mit den aktuellen Werten — Bestätigen aktualisiert den Baustein direkt im Editor, ohne ihn löschen und neu einfügen zu müssen. Beim Laden einer Seite wird vorhandener Shortcode-Text automatisch in den Vorschau-Baustein umgewandelt; gespeichert wird weiterhin nur der reine `[tag ...]`-Text (`public/assets/js/shortcode-picker.js`, `admin-pages-summernote.js`). Dafür unterstützt `Media::list()`/`/admin/media/list` jetzt einen `ids`-Filter, um mehrere Mediathek-Einträge gezielt nachzuladen.

### Fixed

- **Carousel-Bilder ohne feste Höhe**: Die generische Theme-Regel `.esse-content img { height: auto }` (`esse-base.css`) war spezifischer als `.esse-carousel-img { height: 100% }` und gewann daher im CSS-Cascade — die Bilder im `[carousel]`-Widget wurden dadurch in ihrem natürlichen Seitenverhältnis statt mit fester Höhe/`object-fit: cover` gerendert (ungleich hohe, am oberen Rand „klebende" Bilder statt einheitlich gefüllter Slides). Der Selektor in `esse-ui.css` ist jetzt zweifach geklasst (`.esse-carousel .esse-carousel-img`) und gewinnt zuverlässig, unabhängig vom Host-Theme — analog zum bereits bestehenden, korrekt funktionierenden Bootstrap-Carousel auf der Startseite (`.esse-content .carousel-item img`).
- **Carousel-Pfeile optisch nicht mittig**: Die Pfeil-Buttons nutzten Textzeichen (`‹`/`›`) als Inhalt, deren Glyphen je nach Schrift nicht exakt im Zeilenkasten zentriert sind und dadurch nach unten verschoben wirkten. Ersetzt durch ein geometrisches Chevron (zwei rotierte Rahmenkanten statt Schriftzeichen), das unabhängig von Font-Metriken exakt mittig sitzt.

### Security

- **IP-basierte Sperre statt Session-Zähler**: Die Brute-Force-Bremse für `/login`, `/admin/verify-2fa` und `/admin/forgot-password` beruhte bisher auf `$_SESSION`-Zählern und war durch einfaches Verwerfen des Session-Cookies umgehbar. Neue Klasse `core/RateLimit.php` zählt Fehlversuche stattdessen IP-basiert (bei 2FA pro Benutzer) in einer DB-Tabelle (`rate_limits`) und übersteht damit auch eine neue Session. Schwellen unverändert: 5 Versuche/60s für Login und 2FA, 3 Anfragen/15min für Passwort-Reset.
- **Direktzugriff auf `admin/*.php` blockiert**: `admin/` hatte als einziges Code-Verzeichnis kein eigenes `.htaccess` (anders als `core/`, `config/`, `storage/`, `pages/`, `plugins/`, `tests/`) — admin/*.php-Dateien liefen bei Direktaufruf außerhalb des zentralen Bootstraps (live als HTTP 500 statt 403 beobachtet, da Autoloader/Session/CSRF-Schutz fehlten). Neue `admin/.htaccess` (`Require all denied`) zwingt alle Zugriffe wieder über `index.php`/den Router. Ebenso neue `themes/.htaccess`, die analog zu `public/uploads/.htaccess` nur die PHP-Ausführung sperrt — CSS/JS/Bilder unter `themes/<name>/assets/` bleiben weiterhin direkt erreichbar.
- **Backup-Zugriff zu breit berechtigt**: Backup erstellen/herunterladen/löschen (`admin/backup.php`, `admin/backup-download.php`) erforderte bisher nur die allgemeine Permission `manage_settings`, die standardmäßig an die Rolle `admin` vergeben wird — ein Backup enthält aber den vollständigen Datenbank-Dump inkl. verschlüsselter SMTP-Zugangsdaten, TOTP-Secrets und Passwort-Hashes. Neue, granulare Permission `manage_backups` (`core/Auth.php`), die wie `php_upload` standardmäßig an keine Rolle vergeben wird, sondern explizit zugewiesen werden muss. **Breaking Change**: bestehende `admin`-Nutzer verlieren den Zugriff auf `/admin/backup`, bis ein Forge-Nutzer ihnen `manage_backups` über die Rollen- oder Benutzer-Berechtigungen zuweist. Restore bleibt unverändert exklusiv Forge vorbehalten.
- **`php_upload`-Warnhinweis auch in der Rollenübersicht**: Die gefährliche Permission `php_upload` (erlaubt das Hochladen ausführbarer .php/.html-Seiten) zeigte bisher nur im Benutzer-Berechtigungs-Override (`admin/users/form.php`) ein rotes „Gefährlich"-Badge. `admin/roles.php` zeigt jetzt dasselbe Warn-Icon, damit die Gefahr auch bei der Rollenkonfiguration erkennbar ist.
- **Re-Authentifizierung bei sicherheitsrelevanten Profil-Aktionen**: E-Mail ändern, Passwort ändern sowie Passkey hinzufügen/umbenennen/entfernen (`pages/profil.php`, `core/routes.php`) verlangten bisher nur ein gültiges CSRF-Token, kein aktuelles Passwort — anders als die bereits bestehende TOTP-Deaktivierung. Neuer zentraler Helfer `Auth::verifyCurrentPassword()` macht die Passwort-Bestätigung jetzt für alle fünf Aktionen einheitlich Pflicht; beim Passkey-Hinzufügen wird das Passwort direkt vor dem WebAuthn-Browser-Dialog abgefragt (`register-options`-Route, `public/assets/js/webauthn.js`, `profile-passkey.js` mit neuem Modal statt `window.prompt()`).
- **Verschlüsselung ohne Integritätsschutz**: `core/Crypto.php` nutzte AES-256-CBC ohne HMAC/Auth-Tag (manipulierte Geheimtexte wurden nicht zuverlässig erkannt) und verkürzte den Schlüssel durch `substr()` effektiv auf 16 Byte Entropie. Umgestellt auf `sodium_crypto_secretbox()` (AEAD, XSalsa20-Poly1305) mit neuem `ENC2:`-Format und voller Schlüssel-Entropie via `sodium_crypto_generichash()`. Bestehende `ENC:`-Werte (SMTP-Passwort, GitHub-Token, TOTP-Secrets) werden weiterhin korrekt gelesen und erst bei nächster Neueingabe automatisch ins neue Format migriert — kein Datenverlust, kein erzwungenes Re-Encrypt aller Bestandsdaten. Neuer Test `tests/CryptoTest.php`.
- **Installer bleibt auch ohne Lock-Datei gesperrt**: `install/index.php` prüfte bisher ausschließlich die Existenz von `install/installed.lock`, um eine erneute Installation zu verhindern. Als zweites, unabhängiges Gate wird jetzt zusätzlich geprüft, ob bereits eine `config.php` existiert — verhindert, dass eine verlorene Lock-Datei (Backup, manuelles Aufräumen) den Installer wieder öffnet und z.B. einen neuen Forge-Account auf der bestehenden Datenbank anlegen ließe. `install/installed.lock` bleibt bei Auto-Updates weiterhin als einziger Pfad unter `install/` explizit geschützt (`core/Updater.php`), da Updates `install/index.php` sonst aus dem Release-ZIP wiederherstellen würden.

### Changed

- **Flash-Messages zentralisiert**: Das in rund 15 Admin-Seiten verstreute `$_SESSION['flash']`-Pattern wurde durch die neue Klasse `core/Flash.php` (`Flash::set()` / `Flash::consume()`) ersetzt. Verhalten unverändert, `PLUGIN_GUIDE.md` aktualisiert.

## [0.7.0-alpha] - 2026-06-16

### Added

- **Shortcode/Widget-System**: Plugins können Shortcodes wie `[news limit="5"]` registrieren (`Esse\Shortcodes::register()` bzw. `registerShortcode()` in `core/Plugin.php`), die beim Rendern einer Seite (`core/PageRenderer.php`) durch das Handler-HTML ersetzt werden. Im Seiteneditor (`admin/pages/form.php`) gibt es dafür einen neuen Summernote-Button „Widget einfügen", der über `/admin/shortcodes/list` alle registrierten Widgets mit Beschreibung und Parametern anzeigt und den passenden `[tag attr="..."]`-Code in den Inhalt einfügt.
- **Überschrift pro Seite ausblendbar**: Neue Karte „Layout" im Seiteneditor (`admin/pages/form.php`) mit Checkbox „Überschrift auf der Seite ausblenden" (`hide_title`-Spalte in `pages`). Titel/Icon werden weiterhin in Menüs, Browser-Tab und SEO-Metadaten verwendet — nur die `<h1>` am Seitenanfang entfällt. Unterstützt von `esse-base`, `esse-cyber` und `esse-dashboard`.
- **Theme-gerendertes Login/Passwort-zurücksetzen im esse-base-Theme**: `/login`, `/passwort-vergessen` und `/neues-passwort` werden jetzt über die Hooks `auth.login.render`, `auth.forgot_password.render` und `auth.reset_password.render` (neue Partials `themes/esse-base/templates/{login,forgot-password,reset-password}.php`) im normalen Seiten-Layout mit Navbar und Footer dargestellt — wie bereits bei `/registrieren` — statt im separaten Admin-Look. `/admin/login`, `/admin/forgot-password` und `/admin/reset-password` bleiben unverändert als Fail-Safe.
- **Einheitliches Formular-Layout für `/registrieren`**: Das Registrierungsformular (`pages/registrieren.php`) wird jetzt wie `/login` und `/passwort-vergessen` in einer Karte dargestellt; die doppelte Überschrift (Seitentitel im Layout + eigenes `<h1>`) wurde entfernt.
- **Öffentliche URLs für Passwort-vergessen/-zurücksetzen**: Analog zu `/login` (statt `/admin/login`) gibt es jetzt `/passwort-vergessen` und `/neues-passwort` als öffentliche Aliase für `/admin/forgot-password` und `/admin/reset-password` (`core/routes.php`). Der Reset-Link in der E-Mail verweist jetzt auf `/neues-passwort?token=...`.

### Fixed

- **Passkey-Login im esse-base-Theme**: Das Login-Dropdown in der Navbar (`themes/esse-base/templates/layout.php`) bot bisher keine Möglichkeit zur Passkey-Anmeldung, obwohl `/admin/login` diese bereits unterstützt. Das Dropdown zeigt jetzt — wie auf der Login-Seite — einen „Mit Passkey anmelden"-Button, der nur erscheint, wenn der Browser WebAuthn/Passkeys unterstützt.

## [0.6.1-alpha] - 2026-06-15

### Fixed

- **Bilder im Seiteneditor verkleinern**: Im Summernote-Editor (`admin/pages/form.php`) ragte die untere rechte Resize-Ecke großer Bilder hinter die Scrollbar des Editierbereichs und war praktisch nicht greifbar. Bilder werden im Editor jetzt per `max-width: 100%` auf die Editorbreite begrenzt, der Editierbereich erhält etwas rechten Innenabstand und die Resize-Ecke wird größer und blau hervorgehoben dargestellt, sodass sich Bilder zuverlässig per Drag verkleinern und vergrößern lassen.

## [0.6.0-alpha] - 2026-06-14

### Added

- **Ordner in der Mediathek**: Dateien lassen sich in `/admin/media` jetzt in (verschachtelte) virtuelle Ordner organisieren — Ordner anlegen, umbenennen und löschen (nur wenn leer), Dateien per Edit-Dialog in Ordner verschieben, Navigation per Breadcrumb. Die physische Ablage in `/public/uploads/` und bestehende URL-Referenzen in Seiteninhalten bleiben unverändert; der Mediathek-Picker im Seiteneditor zeigt weiterhin alle Dateien ordnerübergreifend an.
- **Carousel-Bilder im esse-base-Theme**: Bilder in `.carousel-item` werden jetzt per `object-fit: cover` auf eine einheitliche Höhe (450px, mobil 280px) zugeschnitten, sodass alle Slides unabhängig vom Seitenverhältnis gleich groß erscheinen.

### Fixed

- **Icon-Packs im Admin**: Nicht-Standard-Icon-Packs (z.B. Phosphor mit Prefix `ph ph-`) wurden im Admin-Bereich (Sidebar-Navigation, Icon-Picker) nicht angezeigt, da nur das CSS von Bootstrap Icons fest eingebunden war. `admin/layout.php` bindet jetzt zusätzlich `\Esse\Ui::iconPackCssTag()` ein, sodass das aktive Icon-Pack auch im Admin korrekt rendert.
- **Menü-Editor**: Der umrandete Unterpunkt-Bereich mit dem Hinweis „Element hierher ziehen, um es als Unterpunkt einzuordnen" wurde unter jedem Haupteintrag ohne Unterpunkte permanent angezeigt und sorgte für viel Leerraum. Statt dieses großen Bereichs zeigt jeder Haupteintrag ohne Unterpunkte nun eine kleine, feste Drop-Zone „Untermenü" direkt vor dem Icon in seiner Zeile. Beim Ablegen eines Eintrags darauf wird daraus der erste Unterpunkt und der vollständige, eingerückte Unterpunkt-Bereich erscheint darunter; wird der letzte Unterpunkt entfernt, kehrt die kleine Drop-Zone zurück. Die Drop-Erkennung wurde zudem per `forceFallback` und größerem `emptyInsertThreshold` deutlich treffsicherer gemacht.

## [0.5.0-alpha] - 2026-06-14

### Added

- **SEO-Grundlagen**: Neue Karte „SEO" in `admin/pages/form.php` für eine seitenspezifische Meta-Beschreibung (`meta_description`, max. 300 Zeichen), gerendert als `<meta name="description">` und Open-Graph-Beschreibung im `esse-base`-Theme.
- **SEO-Einstellungen**: Neue Karte „SEO" in `admin/settings.php` mit globaler Standard-Meta-Beschreibung (Fallback, wenn eine Seite keine eigene gesetzt hat), Schalter für `/sitemap.xml` und einem optionalen eigenen `/robots.txt`-Inhalt.
- **Neue Routen** `/robots.txt` (Standardregeln oder eigener Inhalt aus den Einstellungen, inkl. Sitemap-Verweis) und `/sitemap.xml` (XML-Sitemap aller veröffentlichten, öffentlich sichtbaren Seiten — nur aktiv, wenn in den Einstellungen aktiviert).
- **Profilfelder**: Neuer Admin-Bereich „Profilfelder" (`admin/user-fields.php`, unter Einstellungen) zum Anlegen frei konfigurierbarer Zusatzfelder (Text, Mehrzeiliger Text, Auswahl, Checkbox, Datum) inkl. Pflichtfeld-Option, Sortierung und Sichtbarkeit für Registrierung/Profil. Felder werden bei `/registrieren`, `/profil` und in der Admin-Benutzerverwaltung (`admin/users/form.php`) angezeigt, validiert und in `user_field_values` gespeichert.
- **Mediathek**: Neuer Admin-Bereich „Mediathek" (`/admin/media`) zur Verwaltung aller hochgeladenen Dateien — Suche, Filter nach Typ, Markierung als „privat", Verwendungsnachweis und Löschen (inkl. Datei-Lösch-Schutz für versteckte Dateien wie `.htaccess`).
- **Mediathek-Picker im Seiteneditor**: Neuer Button „Aus Mediathek einfügen" im Summernote-Editor (`admin/pages/form.php`), der einen Auswahldialog (`EsseMedia.open()`) mit allen vorhandenen Medien öffnet. Die Picker-Funktionalität ist als globales `window.EsseMediaButton` für Plugins wiederverwendbar (siehe `PLUGIN_GUIDE.md`).
- **Quelle-Anzeige in der Mediathek**: Sowohl in der Mediathek-Übersicht (`/admin/media`) als auch im Auswahldialog „Aus Mediathek einfügen" wird jetzt ein Badge mit der Quelle jeder Datei angezeigt (z. B. „Mediathek", „Editor", „Gallery", „Download"), abgeleitet aus dem `source`-Wert von `Media::register()` über die neue zentrale `Media::sourceLabel()`.

### Fixed

- **Admin-Sidebar**: Navigation scrollt jetzt unabhängig vom Hauptinhalt, sodass alle Menüpunkte auch bei langen Seiten erreichbar bleiben.

## [0.4.0-alpha] - 2026-06-12

### Security

- **Stored XSS im Benutzerformular** (`admin/users/form.php`, Rollen-Dropdown): Das Label einer benutzerdefinierten Rolle (`admin/roles.php` → „Eigene Rolle erstellen") wurde unescaped ausgegeben — ein Forge/Admin könnte beim Anlegen einer eigenen Rolle HTML/JS in den Rollennamen schreiben, das dann bei jedem Aufruf des Benutzerformulars ausgeführt würde. Behoben durch `htmlspecialchars()` auf Label und Value. Im Rahmen einer gezielten SQL-Injection-/XSS-Durchsicht der Admin-Templates gefunden — keine weiteren Funde (alle Queries parametrisiert, übrige Ausgaben bereits korrekt escaped).

### Added

- **Sicherheits-Protokoll: Self-Update**: CMS-Updates über `/admin/update/run` werden jetzt ebenfalls im Audit-Log erfasst (`self_update`/`self_update_failed`), inkl. Quell- und Ziel-Version sowie Fehlermeldung bei fehlgeschlagenem Update.
- **Sicherheits-Protokoll: Backup-Wiederherstellung**: Restore eines Backups über `/admin/backup` wird im Audit-Log erfasst (`backup_restored`/`backup_restore_failed`), inkl. Dateiname und Fehlermeldung bei Fehlschlag.
- **Sicherheits-Protokoll: Einstellungsänderungen**: Änderungen an sicherheitsrelevanten Einstellungen (Registrierung an/aus, Audit-Log-Aufbewahrungsfrist, SMTP-Passwort, GitHub-Token, Pre-Release-Updates) werden im Audit-Log erfasst (`settings_changed`), bei einfachen Werten inkl. alt/neu, bei Geheimnissen nur als „geändert" ohne Klartext.

### Changed

- **Menü-Editor: Drag & Drop über Ebenen hinweg** (`admin/menus/form.php`): Einträge können per Drag & Drop nicht mehr nur innerhalb, sondern auch zwischen Haupt- und Unterebene verschoben werden (eine gemeinsame SortableJS-Gruppe statt getrennter Listen). Die bisherigen Einrücken/Ausrücken-Buttons entfallen. Jede Hauptebene zeigt jetzt immer eine Drop-Zone für Unterpunkte (mit Platzhaltertext, falls leer). Ein Eintrag mit eigenen Unterpunkten kann nicht selbst zum Unterpunkt gemacht werden, und die Verschachtelung bleibt serverseitig auf zwei Ebenen begrenzt (`reorder`-Aktion validiert `parent_id` gegen die übermittelten Top-Level-IDs).

### Fixed

- **Footer-Menü im `esse-base`-Theme** (`themes/esse-base/templates/layout.php`): Ein im Menü-Editor gesetztes Icon wurde bei Footer-Einträgen nicht angezeigt, nur das Label. Wird jetzt analog zum Seiten-Icon pack-agnostisch über `Esse\Ui::icon()` gerendert (mit Fallback auf volle CSS-Klassen für ältere Icon-Werte).

## [0.3.0-alpha] - 2026-06-11

### Added

- **Sicherheits-Protokoll** (`core/AuditLog.php`, `/admin/logs`): protokolliert sicherheitsrelevante Ereignisse — erfolgreiche/fehlgeschlagene Logins (Passwort, 2FA, Passkey), Konto-Sperrungen nach zu vielen Fehlversuchen, Passwort-Reset-Anfragen/-Abschlüsse, Aktivierung/Deaktivierung/Neugenerierung von 2FA und Passkeys, Benutzerverwaltung (Anlage, Rollenänderung, zusätzliche Berechtigungen, Aktivierung/Deaktivierung), Rollen-/Berechtigungsänderungen (Rolle erstellt/gelöscht, Berechtigungen je Rolle geändert), Änderungen am eigenen Profil (Passwort, E-Mail), Hochladen von PHP-/HTML-Seiten sowie Plugin-Verwaltung (Installation, Update, Aktivierung/Deaktivierung, Deinstallation). Zugriff über die bestehende `view_logs`-Berechtigung. **DSGVO-konform**: Speicherung erfolgt auf Basis berechtigten Interesses (Art. 6 Abs. 1 lit. f DSGVO, ErwG 49 — Netz- und Informationssicherheit), Einträge werden nach einer einstellbaren Frist (Standard 90 Tage, Admin → Einstellungen) automatisch gelöscht.

## [0.2.2-alpha] - 2026-06-11

### Security

- **`tests/` auf Servern absichern**: `tests/.htaccess` (`Require all denied`, analog zu `core/`, `config/`, `storage/`, `pages/`, `plugins/`) verhindert direkten Web-Zugriff, falls das Verzeichnis z.B. via `git clone` auf einen Produktivserver gelangt. Zusätzlich schließt `.gitattributes` (`export-ignore`) `tests/` sowie `.agents/`, `.codex/`, `.claude/` von GitHub-Release-Zipballs aus, sodass Installer und Self-Updater diese Dateien gar nicht erst ausliefern.

### Added

- **Automatisierte Tests** (`tests/`): schlanker, abhängigkeitsfreier Test-Runner (`tests/run.php`, kein Composer/PHPUnit nötig) mit Tests für `Updater::isNewer()` (Versionsvergleich), `Totp` (Code-Generierung/-Verifikation nach RFC 6238), `Captcha` (Rechenaufgabe, Honeypot, Mindestzeit, Single-Use), `Auth::csrfToken()`/`verifyCsrf()` (CSRF-Schutz), `Auth::meetsRole()`/`can()`/`canAny()` (Rollen-Hierarchie und Berechtigungen ohne DB), `Hooks` (Listener-Reihenfolge/Priorität, Filter, Clear) sowie `Schema::tables()` (Kern-Tabellen). Dokumentiert in README.md unter „Tests".
- **Integrationstests** (`tests/integration/`): eigener Runner (`tests/integration/run.php`) startet einen PHP-Built-in-Server gegen eine separate Test-Datenbank (`esse_test`, einmaliges Setup über `tests/integration/setup-db.sql`) und prüft per cURL-Client echte HTTP-Abläufe — Login (falsches Passwort, Sperre nach 5 Fehlversuchen, korrekter Login, CSRF-Schutz bei Login/Abmelden), Seiten-Sichtbarkeit (`/profil`, `/registrieren` für Gast vs. eingeloggten Nutzer), Security-Header (CSP, X-Frame-Options, X-Content-Type-Options etc. auf jeder Antwort), Rollen-/Berechtigungs-Durchsetzung auf Admin-Routen (`/admin`, `/admin/pages`, `/admin/users` für Gast/Member/Forge), Datei-Upload-Härtung (`/admin/files/upload`: Berechtigungs-/CSRF-Prüfung, Ablehnung von `.php`-Dateien und Bild-Dateien mit gefälschter Endung, erfolgreicher PNG-Upload), CSRF-Schutz beim Löschen von Seiten (`/admin/pages/delete/{slug}`) sowie der Passwort-Reset-Flow (`/admin/forgot-password`, `/admin/reset-password`: ungültiger/abgelaufener/einmal verwendbarer Token, Mindestlänge, Passwort-Bestätigung, anschließender Login mit neuem Passwort). Gemeinsames DB-Schema (`core/Schema.php`) wird jetzt sowohl vom Installer als auch von den Tests genutzt.

## [0.2.1-alpha] - 2026-06-10

### Fixed

- **Updater-Live-Ausgabe (`admin/update/run`)**: Bei Hosting-Setups mit nginx→Apache(mod_proxy_fcgi)→PHP-FPM (z.B. HestiaCP) puffern die Proxy-Ebenen kleine SSE-Antworten (wenige hundert Bytes) bis zum Skriptende, sodass der Update-Fortschritt erst komplett am Ende statt live erscheint, obwohl das Update selbst korrekt durchläuft. `X-Accel-Buffering: no` wirkt nur gegen nginx, nicht gegen die Apache-Zwischenebene. Fix: ein ~64 KB großer SSE-Kommentar-Block wird vor den eigentlichen Events gesendet, der die Proxy-Puffer sofort zum Durchreichen zwingt.

## [0.2.0-alpha] - 2026-06-10

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
- README: Feature-Liste, Sichtbarkeitswerte, Plugin-Repo-Beschreibung und Theme-Verzeichnis an den aktuellen Core-Stand angepasst.
- Admin-Login (`admin/login.php`), „Passwort vergessen"/„Neues Passwort setzen" (`admin/forgot-password.php`, `admin/reset-password.php`) und Admin-Sidebar (`admin/layout.php`) zeigen jetzt den konfigurierten `site_name`/`site_slogan` statt fest „ESSE CMS"/„forge your web." — wichtig für produktive Instanzen mit eigenem Markennamen. Der Installer (`install/index.php`) behält bewusst das ESSE-CMS-eigene Branding, da dort noch keine Site konfiguriert ist.
- Einstellungen: redundante Karte „Theme & Menüpositionen" entfernt (Themes sind direkt über die Admin-Navigation unter „Themes verwalten" erreichbar).
- **PLUGIN_GUIDE.md / THEME_GUIDE.md — README-Vorlage und Badges**: neuer Abschnitt „README-Vorlage" mit einheitlichem Gliederungsschema für Plugin- und Theme-READMEs sowie Badge-Konvention — Release-Badge zieht die Version live über die GitHub-API (immer aktuell, keine manuelle Pflege, keine Drift zur `plugin.json`/`theme.json`-Version), Lizenz- und CMS-Kompatibilitäts-Badge bleiben statisch, da sie sich praktisch nie ändern. Dazu der Hinweis, dass das „ESSE CMS"-Badge bei Plugins exakt `requires.esse` aus `plugin.json` entsprechen sollte (wird beim Aktivieren tatsächlich gegen `ESSE_VERSION` geprüft, siehe `admin/plugins/index.php`), bei Themes dagegen rein informativ ist (kein `requires`-Feld, keine Versionsprüfung im Core). README.md hat denselben Badge-Block (Release/Lizenz/PHP-Version) erhalten.
- **PLUGIN_GUIDE.md / THEME_GUIDE.md — Navigation und Checklisten**: Inhaltsverzeichnis mit Sprunglinks ergänzt (beide Guides sind mittlerweile über 1000 bzw. 500 Zeilen lang). Plugin-Checkliste um die bisher fehlenden Kern-Routen `/` und `/login` in der Slug-Konflikt-Liste erweitert. Theme-Checkliste um einen Hinweis auf die `auth.login.render`-Hooks ergänzt (inkl. Pflichtfeld `name="_form" value="admin_login"`) sowie um `CHANGELOG.md`/`LICENSE` — die Theme-Grundstruktur und -Checkliste nannten bisher nur `README.md`, obwohl beide bestehenden Theme-Repos (esse-dashboard, esse-cyber) auch CHANGELOG und LICENSE mitbringen und die Plugin-Guide alle drei bereits listete.
- **PLUGIN_GUIDE.md / THEME_GUIDE.md — CSP-Richtlinien**: neuer Abschnitt „CSP-Richtlinien" beschreibt die Standard-Policy `script-src 'self'; style-src 'self'` und wie Plugins/Themes sie einhalten — keine Inline-Skripte/-Styles, JS/CSS als externe Assets, PHP-Daten über `$extraScriptConfig`/JSON-Konfig-Blöcke statt Inline-`<script>`. Die Plugin-Guide-Beispiele für `$extraScripts` und das Summernote-Setup wurden entsprechend auf `$extraScriptConfig`/`$extraScriptFiles` und externe CSS umgestellt; die Theme-Checkliste enthält jetzt den Punkt „CSP-kompatibel".
- **THEME_GUIDE.md — Passkey- und 2FA-Anforderungen für eigene Login-Seiten**: Themes, die `/login` über `auth.login.render` selbst rendern, müssen den Core-Passkey-Block (`passkey-login-block`/-`btn`/-`error`, `passkey-login-config`, `webauthn.js`, `passkey-login.js`) übernehmen, sonst verschwindet die passwortlose Anmeldung aus der Theme-Loginseite. 2FA/TOTP läuft ausschließlich zentral über `/admin/verify-2fa` — Themes dürfen hierfür keinen eigenen Dialog bauen. Beide Punkte sind jetzt auch in der Theme-Checkliste verankert.
- **PLUGIN_GUIDE.md / THEME_GUIDE.md — CSP-Richtlinien vervollständigt**: die bisher gezeigte Kurzform `script-src 'self'; style-src 'self'` durch die vollständige, tatsächlich von `core/SecurityHeaders.php` gesendete Policy ersetzt (inkl. `default-src`, `base-uri`, `object-src 'none'`, `frame-ancestors`, `form-action`, `img-src`/`font-src` mit `data:`, `connect-src 'self'`). Hinweise ergänzt, dass `connect-src 'self'` `fetch()`/`XHR` zu Fremd-Domains blockiert (externe APIs müssen über eine eigene PHP-Route geproxyt werden) und dass `img-src`/`font-src` zwar `data:`-URIs, aber keine fremden Hosts erlauben.
- **PLUGIN_GUIDE.md — kleinere API-Dokulücken geschlossen**: `registerPage()` hat einen bisher undokumentierten vierten Parameter `$visibility` (`public`/`guest_only`/`registered`/`roles`, Default `public`) zur Vorbelegung der Sichtbarkeit neuer Plugin-Seiten; `Auth::id()` gibt `?int` zurück (nicht `int` — `null`, wenn niemand eingeloggt ist); der Helper `$this->route()` als Alternative zu `Router::get()/post()` ist jetzt erwähnt.

### Removed

- `public/vendor/quill/` (Quill 1.3.7) entfernt — unbenutzter Vendor-Code, das Admin-Panel nutzt ausschließlich Summernote als WYSIWYG-Editor.

### Fixed

- Security-Migrationen fuer TOTP/Passkeys laufen jetzt bereits beim Boot, auch ohne eingeloggten Nutzer. Dadurch werden `totp_*`-Spalten und `webauthn_credentials` rechtzeitig angelegt, bevor Login-, Passkey- oder Profil-Flows darauf zugreifen.
- Session-Cookie-Hardening: `PHPSESSID` bekommt auf HTTPS-Installationen jetzt zuverlaessig das `Secure`-Flag, auch wenn PHP hinter Hosting-/Proxy-Setups `$_SERVER['HTTPS']` nicht setzt, solange `ESSE_URL` mit `https://` konfiguriert ist.
- `PageVisibility::migrate()`: die `visibility`-Spalte in den Tabellen für Seiten und Sichtbarkeits-Overrides wird auf bestehenden Installationen jetzt per `ALTER TABLE` auf `VARCHAR(20) NOT NULL DEFAULT 'public'` korrigiert, falls sie noch aus einer älteren Schema-Version stammt — verhindert Fehler beim Speichern der neuen Sichtbarkeitswerte (`guest_only`, `registered`, `roles`) auf Bestandsinstallationen.
- Icon-Picker (`public/assets/js/admin-icon-picker.js`): die deutschen Suchsynonyme (z.B. „haus" → `house`, „einstellungen" → `gear`/`sliders`), die beim Verschieben der Inline-Skripte auf externe JS-Assets verloren gegangen waren, sind wiederhergestellt.
- `vendor/phpmailer/` enthält jetzt die `LICENSE`-Datei — analog zu `vendor/webauthn/`, auf das in der Passkeys-Beschreibung als Vorbild verwiesen wird.
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
- Plugins are maintained in separate repositories and are not bundled with the core release
