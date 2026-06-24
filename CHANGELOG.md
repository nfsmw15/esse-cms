# Changelog

All notable changes to ESSE CMS will be documented in this file.

## [Unreleased]

### Added

- **Zentrale, paket-typ-unabhängige Repo-Kanäle**: Bisher hatte nur Plugins eine Kanalverwaltung (`plugin_repos`), Themes durchsuchten hartkodiert immer nur `nfsmw15`, Icon-Packs hatten gar keine "Verfügbar"-Suche. Ein Kanal ist jetzt einfach "ein vertrauenswürdiger GitHub-Account" — was er anbietet ergibt sich allein aus den Topic-Tags (`esse-plugin`/`esse-theme`/`esse-iconpack`) auf seinen Repos, nicht aus einem Feld am Kanal. Tabelle `plugin_repos` umbenannt in `repo_channels` (bestehende Kanäle bleiben beim Update erhalten, Migration läuft unconditional bei jedem Request — siehe Lehre aus der `manage_repos`-Migration in 0.8.7).
- **Neue Seite `/admin/repos`**: Zentrale Verwaltung aller Kanäle (Hinzufügen/Entfernen, jetzt für alle drei Pakettypen gemeinsam). Forge kann zusätzlich die Vertrauensstufe eines Kanals nachträglich umschalten (`repo_trust_changed`-Event) — bewusst Forge-only, da ein Admin mit `manage_repos` einen selbst hinzugefügten Kanal nicht auch selbst als vertrauenswürdig markieren darf. `admin/plugins`/`admin/themes`/`admin/iconpacks` verlinken jetzt hierhin statt eigener Kanal-Verwaltung; die bisherige "Kanäle"-Card auf `/admin/plugins` entfällt.
- **Themes durchsuchen jetzt alle aktiven Kanäle** statt nur `nfsmw15` fest im Code — Community-Kanäle funktionieren für Themes jetzt genau wie für Plugins.
- **Icon-Packs bekommen einen vollen "Verfügbar"-Tab**: Installiert/Verfügbar-Tabs analog zu Plugins/Themes, inkl. `install_from_repo` (nutzt die bestehende gehärtete `packageInstallZip()`), Cache und `GitHubApi::searchIconPacks()` (Topic `esse-iconpack`).
- **Lightbox + Download-Button in der Mediathek**: Klick auf ein Bild-Thumbnail öffnet jetzt eine Vorschau in voller Größe (`#mediaLightboxModal`) statt nichts zu tun; die Karten-Aktionen haben neben Bearbeiten/Löschen jetzt einen eigenen Download-Button (`<a download>`), der für öffentliche wie private Dateien gleich funktioniert.

### Fixed

- **"Plugin installieren"-Card lag vor der Plugin-Liste**: Bei Themes liegt die Upload-Card (bewusst nur für den Notfall gedacht) unten, bei Plugins lag sie oben. Jetzt einheitlich unten angeordnet.
- **Veraltete Repo-Kanal-Aktionen auf `/admin/plugins`, `/admin/themes`, `/admin/iconpacks` antworteten mit stillem 200**: Seit der Zentralisierung der Repo-Kanäle unter `/admin/repos` gab es für `add_repo`/`remove_repo`/`toggle_trust` auf den drei Pakettyp-Seiten keine Behandlung mehr — ein POST mit einer solchen `_action` traf keinen `if`-Zweig und fiel bis zur normalen Seitenausgabe durch (HTTP 200, nichts passiert). Sicherheitstechnisch unkritisch, aber als POST-Antwort irreführend. Jede nicht (mehr) unterstützte Aktion am Ende der jeweiligen POST-Behandlung liefert jetzt klar 403.
- **Backup-Wiederherstellung über `/admin/backup` lief praktisch immer in den Timeout**: `Updater::dbImport()` führte jedes SQL-Statement aus dem Dump einzeln per PDO mit Autocommit aus — bei Tabellen mit vielen Zeilen (z.B. Plugin-Statistikdaten, >80.000 Einzeil-INSERTs in einem Praxisfall) bedeutete das einen eigenen fsync-Commit pro Zeile und damit mehrere Minuten Laufzeit, weit über jedem Web-Request-Timeout. `PDO::beginTransaction()` löst das nicht, da `DROP`/`CREATE TABLE` im Dump (pro Tabelle) in MySQL immer implizit committen — das beendet eine über die PDO-API verwaltete Transaktion vorzeitig, ohne dass PDO das merkt. Ein erster Versuch über den `mysql`-CLI-Client funktionierte zwar lokal, scheiterte aber live: `exec()`/`proc_open()` sind im Web-PHP-FPM-Pool des Hosters deaktiviert (anders als im CLI-PHP, das die Lücke beim Testen verschleierte). Tatsächlicher Fix: `SET autocommit=0` direkt per SQL statt über `PDO::beginTransaction()` — INSERTs zwischen zwei DDL-Anweisungen sammeln sich dann automatisch zu einer Transaktion, rein über PDO ohne Shell-Aufruf. Laufzeit in der Praxis von >20 Minuten auf ~25 Sekunden für denselben Datensatz. Zusätzlich `set_time_limit(0)` für die Restore-Aktion.

- **Backup-Restore ließ nach dem Backup neu hinzugekommene Dateien stehen**: `Updater::restore()` schrieb Dateien aus dem Backup zurück, löschte aber nie etwas — eine nach dem Backup hochgeladene Mediendatei blieb nach einem Restore z.B. weiterhin unter `/public/uploads/...` per HTTP erreichbar (HTTP 200), obwohl der zugehörige DB-Eintrag durch den DB-Restore bereits weg war. Restore bietet jetzt zwei Modi: **Vollständig** (neu, Standard) entfernt zusätzlich alle Dateien, die nicht (mehr) im Backup stehen — Pfade, die ein Backup grundsätzlich nie erfasst (`storage/cache/`, `storage/backups/`, `storage/update_tmp/`, `public/vendor/`), werden dabei nie angetastet. **Merge** entspricht dem bisherigen Verhalten; die Admin-Oberfläche weist jetzt explizit darauf hin, dass dabei neue Dateien seit dem Backup bestehen bleiben.

### Security

- **Repo-Kanal-Bypass bei `install_from_repo`**: `/admin/plugins`, `/admin/themes` und `/admin/iconpacks` prüften bei der Installation aus einem Repo nur das `owner/repo`-Format per Regex, nicht ob der Owner überhaupt ein konfigurierter, aktiver Kanal ist. `manage_plugins`/`manage_themes`/`manage_settings` sind von `manage_repos` (Kanalverwaltung) getrennte Berechtigungen — ein Nutzer ohne `manage_repos` (`/admin/repos` liefert für ihn korrekt 403) konnte trotzdem ein beliebiges GitHub-Repo als Installationsquelle angeben. Kein direktes RCE (GitHub-Antwort wird weiterhin normal validiert), aber eine Umgehung der eigentlich gewollten Vertrauensgrenze „nur aus konfigurierten Kanälen" — in Kombination mit der bestehenden Update-Erkennung über den Paketnamen (gleicher Slug = Ersetzen der bereits installierten Version) bei Plugins/Themes potenziell PHP-Code-Ersetzung außerhalb der Kanalkontrolle. Neue gemeinsame Prüfung `packageRepoChannelAllowed()` (`admin/package-install.php`) an allen drei Stellen vor dem GitHub-Aufruf.
- **Reflected XSS über `/login?redirect=...` im JSON-Script-Block**: `passkey-login-config` (und drei weitere `<script type="application/json">`-Blöcke: `admin/layout.php` als zentrale Ausgabestelle für alle `$extraScriptConfig`-Werte, das Frontend-Layout sowie `pages/profil.php`) gaben Werte per `json_encode(..., JSON_UNESCAPED_SLASHES | ...)` ohne `JSON_HEX_TAG` aus. Ein `redirect`-Parameter wie `/</script><script>alert(1)</script>` brach dadurch aus dem JSON-Block aus und fügte beliebiges HTML/Script in die Seite ein — `script-src 'self'` (CSP) hätte die Ausführung eines so eingeschleusten Scripts zwar geblockt, nicht aber das Einschleusen beliebigen anderen HTML (z.B. ein Phishing-Formular). Alle vier Stellen nutzen jetzt zusätzlich `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` (das `esse-cyber`-Theme hatte dieses Muster bereits korrekt umgesetzt, diente als Vorlage für den Fix). Gleiche Korrektur im separaten `esse-dashboard`-Theme-Repo.
- **Passkey-Login-Endpunkte ohne Rate-Limit**: `/admin/passkey/auth-options` und `/admin/passkey/auth-verify` sind öffentlich per POST aufrufbar, hatten aber (anders als der normale Login) keine Bremse — beliebig viele Anfragen mit ungültigen Credential-Daten erzeugten ungedrosselt `passkey_login_failed`-Audit-Log-Einträge samt DB-Lookup pro Versuch. Kein Account-Takeover möglich (Signaturprüfung schlägt fehl), aber Log-/DB-Spam durch automatisierte Fehlversuche. Neuer IP-basierter Bucket `passkey_login:<ip>` (10 Versuche/10 Minuten, von beiden Endpunkten gemeinsam genutzt — wiederholte Fehlversuche über `auth-verify` bremsen auch neue `auth-options`-Anfragen derselben IP); oberhalb des Limits weder DB-Lookup noch Audit-Log-Eintrag.
- **Repo-Kanal-Aktionen ohne Berechtigung jetzt geloggt**: Neues Audit-Event `repo_action_forbidden` — sowohl für `add_repo`/`remove_repo` ohne `manage_repos`/Forge als auch für Versuche, die alten Aktionsnamen gegen die jetzt zentralisierten Endpunkte zu fahren.
- **CMS-Selbst-Update (`Updater::apply()`) hatte keine ZIP-Härtung**: Plugin-/Theme-/Icon-Pack-Uploads laufen schon länger durch eine Vorprüfung gegen Zip-Bomben, Dateianzahl und Symlinks (`packageCheckZipLimits()`), das Update-ZIP selbst wurde aber direkt entpackt — ein Symlink-Eintrag wurde nicht abgelehnt, sondern als normale Datei mit dem verlinkten Inhalt geschrieben, eine 25-MB-Einzeldatei und 1100+ Dateien wurden klaglos akzeptiert. Path-Traversal und geschützte Pfade (`config/`, `local.php`, `storage/`, `install/installed.lock`) waren bereits korrekt abgesichert. `/admin/update/run` ist Forge-only und durch einen Einmal-Token geschützt, daher kein akutes Public-RCE — bei einem kompromittierten Update-Kanal oder einem manipulierten Release-ZIP wäre der Schaden aber unnötig groß gewesen. Die Vorprüfung lebt jetzt zentral in `Updater::checkZipLimits()` (gemeinsam von `apply()` und `packageInstallZip()` genutzt statt zwei getrennten Implementierungen) und lehnt bei jedem Verstoß das gesamte Update ab, bevor irgendeine Datei geschrieben wird.
- **Backup-Restore (`Updater::restore()`) hatte ebenfalls keine ZIP-Härtung**: Ein Symlink in einem Backup-ZIP wurde als normale Datei geschrieben, eine stark komprimierte 25-MB-Einzeldatei oder >80 MB Gesamtgröße sowie 1100+ Dateien wurden klaglos akzeptiert — Path-Traversal und geschützte Pfade waren bereits korrekt abgesichert. Backups werden vom CMS selbst erzeugt (kein Public-Upload-Vektor), trotzdem soll ein manipuliertes/korruptes Backup-ZIP nicht unkontrolliert verarbeitet werden. Nutzt jetzt ebenfalls `Updater::checkZipLimits()`, allerdings mit eigenen, deutlich großzügigeren Grenzwerten (500 MB ZIP, 50.000 Dateien, 200 MB pro Einzeldatei, 2 GB entpackt gesamt) — ein echter Backup-ZIP enthält einen vollen DB-Dump (in der Praxis schon 80.000+ Zeilen in einer einzigen Tabelle) plus alle Uploads, die engen Paket-Limits wären dafür zu eng gewesen. Prüfung läuft vor DB-Import und vor jedem Datei-Schreiben.
- **"Private" Mediendateien waren trotz Markierung direkt per öffentlicher URL erreichbar**: `visibility=private` in der Mediathek war bislang nur ein Anzeige-Filter — die Datei landete trotzdem unter `/public/uploads/...` und war für jeden Besucher ohne Login abrufbar, obwohl die Admin-Oberfläche selbst das Gegenteil versprach ("nicht öffentlich erreichbar"). Private Dateien werden jetzt physisch außerhalb des Webroots gespeichert (`storage/uploads/`, bereits per `.htaccess` geschützt bzw. auf Produktion komplett außerhalb des Docroots) und nur noch über einen berechtigungsgeprüften Endpunkt (`/admin/media/file/{id}`) ausgeliefert; ein Sichtbarkeitswechsel verschiebt die Datei jetzt auch physisch zwischen Webroot und geschütztem Speicherort. Bestehende private Dateien werden beim nächsten Request automatisch migriert (unconditional, nicht erst beim nächsten Admin-Login).
- **ZIP-Path-Traversal-Einträge wurden beim Entpacken übersprungen statt das ganze Paket abzulehnen**: `Updater::apply()`/`restore()` ignorierten einen einzelnen `../`-Eintrag bisher stillschweigend (kein Ausbruch aus dem Zielverzeichnis möglich, aber kein Fail-Closed). Die Prüfung läuft jetzt vorab in `checkZipLimits()` mit — ein Traversal-Versuch lehnt jetzt das gesamte ZIP ab, bevor irgendetwas geschrieben wird, statt nur den einzelnen Eintrag zu überspringen.
- **Router-Auth für Backup/Update breiter als die tatsächliche Handler-Prüfung**: `core/routes.php` ließ `/admin/backup*` und `/admin/update*` bereits mit der viel häufiger vergebenen `manage_settings`-Berechtigung passieren, während die Handler selbst korrekt `manage_backups`/`manage_updates` (Forge-only) prüften. Nicht direkt ausnutzbar, da der Handler-Check die eigentliche Hürde war, aber inkonsistent und ein Risiko, falls der Handler-Check je geschwächt wird. Routen geben jetzt dieselbe (engere) Berechtigung wie die Handler vor.
- **Reset-Token-Seite konnte das Audit-Log spammen**: `/admin/reset-password?token=...` ist öffentlich per GET mit beliebigem Token aufrufbar und schrieb bei jedem ungültigen Versuch einen `password_reset_invalid_token`-Eintrag — kein Account-Takeover möglich, aber automatisiertes Durchprobieren hätte das Audit-Log zuspammen und unnötige DB-Last erzeugen können. Eigenes Rate-Limit (20 Versuche/10 Minuten pro IP, separater Bucket von `/admin/forgot-password`); oberhalb des Limits gibt es weder einen DB-Lookup noch einen Audit-Log-Eintrag.
- **Externe Menü-Links erlaubten beliebige URL-Schemes (inkl. `javascript:`)**: `admin/menus/form.php` speicherte den `url`-Wert eines Menüpunkts ungeprüft. CSP entschärft das größtenteils, serverseitige Validierung ist aber die eigentliche Absicherung. Neuer `Menu::isAllowedUrl()`-Check (Allowlist: relative Pfade, `http`, `https`, `mailto`, `tel`) beim Speichern und zusätzlich als Verteidigung in der Tiefe in `Menu::itemUrl()` beim Ausliefern (für Altdaten/andere Schreibwege).
- **Icon-Felder akzeptierten freie Werte statt nur Icon-Klassen-Zeichen**: `admin/pages/form.php` und `admin/menus/form.php` übernahmen den `icon`-Wert ungefiltert (anders als `admin/pages/list.php`, das bereits korrekt auf `[a-z0-9-]` einschränkte) — jetzt einheitlich. Zusätzlich baute die Live-Vorschau im Icon-Picker (`admin-icon-picker.js`) das Vorschau-`<i>`-Element bisher per `innerHTML`-String-Konkatenation; ein eingegebener Wert wie `" onmouseover="..."` hätte aus dem `class`-Attribut ausbrechen können. Vorschau wird jetzt per `document.createElement()`/`className`-Zuweisung gebaut statt über `innerHTML`.
- **Bilduploads übernahmen die Originalbytes unverändert**: `admin/media.php` und `admin/files-upload.php` prüften zwar Dateityp, MIME-Type und `getimagesize()`, speicherten aber die hochgeladene Datei byte-identisch — ein "Polyglot"-Bild (gültig laut `getimagesize()`, aber mit zusätzlich eingebetteten Bytes) blieb dadurch vollständig erhalten. Aktuell kein bekannter Weg, eine so präparierte Bilddatei tatsächlich auszuführen, aber Verteidigung in der Tiefe statt sich allein auf "sieht aus wie ein Bild" zu verlassen. Bilder werden jetzt serverseitig per GD neu geschrieben (`Media::reencodeImage()`) — übrig bleiben nur die tatsächlich dekodierten Bilddaten; schlägt das Re-Encoding fehl (GD kann die Datei trotz erfolgreichem `getimagesize()` nicht dekodieren), wird der Upload abgelehnt. Animierte GIFs werden dabei bewusst auf das erste Frame reduziert (GD-Limitierung).
- **Registrierung hatte keinen Rate-Limit-Schutz**: CSRF, Honeypot, Mathe-Captcha und Mindestzeit waren bereits vorhanden, aber beliebig viele Registrierungsversuche blieben unbegrenzt möglich. Neue IP- und E-Mail-basierte Rate-Limits (5 Versuche/10 Minuten pro IP, 3 Versuche/Stunde pro Ziel-E-Mail) nach demselben Muster wie `/admin/forgot-password`. E-Mail-Verifikation/Admin-Freigabe bewusst nicht Teil dieser Änderung — das wäre ein größerer Funktionsumfang mit eigenen UX-Implikationen.
- **Private Mediendateien im Carousel-Shortcode leakten ihren internen Pfad**: Die Datei selbst blieb geschützt (kein servierbarer Pfad mehr, siehe oben), aber `[carousel]` schrieb `$media['path']` (z.B. `/private-media/<dateiname>`) ungeprüft in die öffentlich gerenderte Seite — Informationsleck plus kaputtes Bild für jeden Besucher, auch für berechtigte eingeloggte Nutzer. `CoreShortcodes::renderCarousel()` prüft jetzt beim Rendern (dieselbe Berechtigung wie der Ausliefer-Endpoint `/admin/media/file/{id}`, den berechtigte Besucher weiterhin nutzen): wer Mediathek-Zugriff hat, sieht das private Bild ganz normal; alle anderen bekommen kein `<img>`-Tag und damit kein kaputtes Bild-Icon — das Bild fehlt für sie einfach in der Slide-Liste, statt einen 403 im Browser zu produzieren.
- **Regression aus dem vorigen Fix: Mediathek-Thumbnails und Bild-Picker brachen für Plugin-eigene private Medien** (z.B. eine Galerie, die ihre Bilder über eine eigene Route wie `/gallery/img/{id}` ausliefert und `visibility=private` setzt, ohne `Media::setVisibility()` zu nutzen): Sowohl `admin/media.php` (Thumbnail), `admin/media-list.php` (Bild-Picker-JSON) als auch `CoreShortcodes::renderCarousel()` leiteten **jedes** als `private` markierte Medium unconditional auf `/admin/media/file/{id}` um — der Endpoint kann aber nur Pfade unter `/private-media/` oder `/public/` auflösen, bei einem Plugin-eigenen Pfad lief er ins Leere (404). In der Praxis: Bilder in der Mediathek ließen sich nicht mehr herunterladen oder per Rechtsklick größer anzeigen, sobald sie als privat markiert waren, der zugrunde liegende Datei-Pfad aber gar nicht zur eigenen `/private-media/`-Konvention gehörte. Neue Methode `Media::usesControlledServing()` prüft jetzt explizit, ob der Pfad tatsächlich der eigenen Konvention entspricht, statt sich allein auf `visibility` zu verlassen — Plugin-eigene Pfade bleiben unverändert und laufen über ihre eigene (bereits zugriffsgeschützte) Route.
- **Plugin-Routen ohne explizites `auth` sind standardmäßig öffentlich**: `Router::get/post()` fällt ohne `auth`-Option auf `public` zurück — bewusst und durchgängig geprüft für CMS-Kernrouten, aber eine leicht übersehbare Falle für Plugin-Code. `Plugin::route()` (der geschützte Helper für Plugins) schreibt jetzt eine `E_USER_WARNING` ins PHP-Errorlog, wenn `auth` fehlt — kein Hartfehler (würde bestehende Plugins mit bewusst öffentlichen Routen brechen), aber eine klar sichtbare Warnung. `PLUGIN_GUIDE.md` weist jetzt explizit darauf hin, `auth` immer mit anzugeben.
- **Audit-Log-Abdeckung erweitert**: Seiten (erstellen/bearbeiten/löschen), Menüs (erstellen/umbenennen/löschen), Profilfelder (erstellen/bearbeiten/löschen/sortieren), Medienordner (erstellen/umbenennen/löschen), Backup-Download, Passkey-Umbenennung sowie Theme-Aktivierung/Theme-Menüzuordnung wurden bisher nicht geloggt. Neue Events: `page_created`/`page_updated`/`page_deleted`, `menu_created`/`menu_updated`/`menu_deleted`, `user_field_created`/`user_field_updated`/`user_field_deleted`, `media_folder_created`/`media_folder_renamed`/`media_folder_deleted`, `backup_downloaded`, `passkey_renamed`, `theme_activated`, `theme_menu_changed`.

### Untersucht, nicht reproduzierbar

- **Berichteter Befund: `manage_backups`/`manage_updates` greifen live nicht eigenständig** — ein Nutzer mit nur `manage_backups` soll auf `/admin/backup` 403 erhalten haben, ein Nutzer mit beiden Rechten auf `/admin/update` ebenfalls. Ausführlich nachgestellt: direkte `Auth::can()`-Prüfung per CLI, echter HTTP-Login+Request gegen die Live-Seite, sowie der vollständige UI-Flow (Forge vergibt die Berechtigung über `/admin/users/edit/{id}`, Ziel-Nutzer ruft die Seite auf) — in allen drei Fällen korrektes Verhalten (200). Keine Codeänderung, da kein reproduzierbarer Fehler gefunden wurde; falls der Befund weiterhin auftritt, sind exakte Reproduktionsschritte (insb. Zeitpunkt relativ zum letzten Deploy) hilfreich.

## [0.8.8-alpha] - 2026-06-23

### Changed

- **Eine ZIP-Installationsroutine für Plugins, Themes und Icon-Packs statt zwei**: `installIconPack()` war eine separate, fast identische Kopie von `packageInstallZip()` (eigene Metadaten-Suche, Slug-Validierung, Extract-Logik) und hatte dabei den `realpath()`-Containment-Check gegen Path-Traversal verloren (aktuell nicht ausnutzbar, da die Slug-Regex ohnehin keine Slashes erlaubt — aber genau die Art Lücke, die durch Duplizierung entsteht). `packageInstallZip()` nimmt jetzt einen dritten Typ `'iconpack'` über eine Typ-Konfiguration (Metadaten-Dateiname, Zielverzeichnis, Pflichtfelder, Endungs-Allowlist) — Limits, Pfad-Sicherheit und Extract-Logik existieren damit nur noch an einer Stelle. `admin/iconpack-install.php` entfällt, `admin/iconpacks.php` ruft direkt `packageInstallZip($tmpFile, 'iconpack')`.

## [0.8.7-alpha] - 2026-06-23

### Added

- **Account-Löschung für deaktivierte Nutzer**: Es gab bisher nur Deaktivieren/Aktivieren, kein Löschen. Neue `delete`-Aktion in `/admin/users/edit/{id}` — nur für bereits deaktivierte Accounts, nie der eigene, nie der letzte aktive Forge-Account. FK-Prüfung vorab: `pages.author_id` ist bereits `ON DELETE SET NULL`, der Audit-Log speichert die E-Mail separat und bleibt damit auch nach der Löschung lesbar — Hard Delete ist damit unkritisch. Neues Event `user_deleted`.

### Security

- **KRITISCH: Icon-Pack-ZIP erlaubte PHP-Ausführung**: `installIconPack()` entpackte jede Datei aus dem ZIP ungeprüft nach `/public/vendor/<slug>/` — eine `probe.php` im Paket landete dort und war direkt per HTTP ausführbar (RCE). Jetzt mit strikter Endungs-Allowlist (`json`, `css`, `map`, Fonts, Bilder) über den gemeinsamen, gehärteten ZIP-Validator; Direct-Upload zusätzlich auf `forge` beschränkt (gleiches Muster wie Plugin-/Theme-Uploads). `installIconPack()`/`discoverIconPacks()` dafür aus `admin/iconpacks.php` in eine reine Funktionsdatei (`admin/iconpack-install.php`) extrahiert.
- **Gemeinsamer ZIP-Validator für Plugins/Themes/Icon-Packs**: `packageCheckZipLimits()` prüft jetzt für alle drei Pakettypen einheitlich Größe, Dateianzahl, Einzeldatei-/Gesamtgröße und Symlink-/Spezialdatei-Einträge vorab anhand der Central-Directory-Metadaten — und lehnt bei einem Treffer das **gesamte Paket** ab statt nur den einzelnen Eintrag zu überspringen. Limits verschärft: 20 MB ZIP (vorher 50), 1000 Dateien (vorher 5000), 20 MB pro Einzeldatei (vorher 50), 80 MB entpackt gesamt (vorher 200) — deckt die im Pentest noch durchgekommenen Fälle ab (135 MB über 3 Dateien, 2000 kleine Dateien, Symlink-Eintrag).
- **Plugin-Repo-Kanäle ohne eigene Berechtigung verwaltbar**: `add_repo`/`remove_repo` in `/admin/plugins` prüften nur die allgemeine `manage_plugins`-Berechtigung. Repo-Kanäle sind aber eine Vertrauensgrenze (woher Plugin-Installationen kommen dürfen) — jetzt zusätzlich `manage_repos` erforderlich, das wie `manage_backups`/`manage_updates`/`php_upload` zu `Auth::FORGE_ONLY_PERMISSIONS` gehört. Bestehende `manage_repos`-Zuweisung der `admin`-Rolle wird per Einmal-Migration entfernt (kann von Forge über `/admin/roles` jederzeit bewusst neu vergeben werden).
- **Audit-Log für Icon-Packs und Repo-Kanäle ergänzt**: `iconpack_installed`/`iconpack_install_failed`/`iconpack_deleted`/`iconpack_delete_failed`, `repo_added`/`repo_removed`/`repo_trust_changed`/`repo_cache_refreshed`.

## [0.8.6-alpha] - 2026-06-22

### Fixed

- **`/admin/plugins` lieferte auf frischen Installationen HTTP 500**: Die Seite fragt die Tabelle `plugin_repos` ab (individuelle GitHub-Repo-Kanäle), die nirgends per `CREATE TABLE` angelegt wurde — existierte nur auf bereits länger laufenden Instanzen als Altlast. Tabelle jetzt in `Schema.php` definiert und über eine Lazy-Migration in `Auth::syncDefaultPermissions()` für Bestandsinstallationen nachgezogen (gleiches Muster wie `Media::migrateDb()`).

### Security

- **ZIP-Upload für Plugins/Themes zu breit erlaubt**: Ein Admin mit `manage_plugins`/`manage_themes` konnte beliebige ZIP-Dateien hochladen und damit PHP-Code direkt installieren. Direct-Upload ist jetzt auf `forge` beschränkt (`admin/plugins/index.php`, `admin/themes/index.php`) — Installation aus Repo-Kanälen bleibt für diese Admins weiterhin möglich.
- **Forge konnte sich selbst zu Admin herabstufen**: Risiko von Selbst-Lockout bzw. Verlust des letzten Forge-Accounts. Selbst-Herabstufung ist jetzt blockiert, wenn kein anderer aktiver Forge-Account existiert, und erfordert sonst eine explizite Bestätigung (gleiches Muster wie die bestehende Forge-Beförderungs-Bestätigung).
- **Plugin/Theme-ZIP ohne Entpack-Limits**: `packageInstallZip()` prüft jetzt ZIP-Größe, Dateianzahl, Einzeldatei- und Gesamt-Entpackgröße vorab anhand der Central-Directory-Metadaten (vor dem Dekomprimieren) und überspringt Symlink-Einträge — schützt vor Zip-Bomben und übergroßen Paketen. Zusätzlich `CURLOPT_MAXFILESIZE` beim Repo-Download als zweite Verteidigungslinie.

## [0.8.5-alpha] - 2026-06-22

### Security

- **Passwortänderung invalidierte andere Sessions desselben Nutzers nicht**: Test mit zwei parallelen Sessions zeigte, dass nach Passwortänderung in Session A die Session B weiterhin gültig blieb (`/admin` lieferte 200). Neue Spalte `users.password_changed_at`; `Auth::login()` merkt sich den Login-Zeitpunkt der Session (`$_SESSION['esse_login_at']`), `Auth::init()` beendet die Session, wenn ihr Login-Zeitpunkt vor der letzten Passwortänderung liegt — wie beim bereits bestehenden Deaktiviert-Check. Greift bei Profil-Selbständerung (`pages/profil.php`), Admin-Passwortänderung für andere Nutzer (`admin/users/form.php`, mit Session-Refresh bei Selbstbearbeitung, um keinen Selbst-Lockout zu erzeugen) und Passwort-Reset (`admin/reset-password.php`). Die Session, die die Änderung selbst durchführt, bleibt gültig.
- **TOTP-Einrichtung ohne Passwortbestätigung**: `totp_setup_start` zeigte Secret/QR-Code ohne erneute Passwortabfrage, anders als Passkey-Hinzufügen und TOTP-Deaktivierung. Jetzt durch ein Bestätigungs-Modal abgesichert (gleiches Muster wie die bestehenden TOTP-/Passkey-Re-Auth-Dialoge).

## [0.8.4-alpha] - 2026-06-22

### Security

- **Audit-Log um sicherheitsrelevante Ereignisse erweitert**: `csrf_failed` wird jetzt zentral in `Auth::verifyCsrf()` protokolliert (mit `path`, `method`, optionalem `_action`) — deckt damit automatisch alle ~34 Stellen im Projekt ab, die diese Methode aufrufen, statt jede einzeln nachzuziehen. Neu außerdem: `media_uploaded`/`media_deleted`/`media_delete_failed`/`media_visibility_changed` (`core/Media.php`, `admin/files-upload.php`, `admin/media.php`), `file_upload_rejected` mit Ablehnungsgrund (Extension/MIME/Größe/Bildprüfung), `update_prepare`, `backup_created`/`backup_deleted`, `plugin_install_failed`/`plugin_uninstall_failed`, `theme_installed`/`theme_install_failed`/`theme_deleted`/`theme_delete_failed` (Theme-Erfolgsereignisse fehlten bisher komplett — Plugins hatten sie schon), `rate_limit_locked` (Passwort-Reset, ergänzt die bestehenden `login_locked`/`2fa_locked`), `password_reset_invalid_token`, `passkey_login_failed`, `totp_setup_started`/`totp_setup_cancelled`. Bestehendes `settings_changed` deckt jetzt auch SMTP-Host/-User/-Port/-Verschlüsselung/-Absender sowie Login-/Logout-Zielseite ab (`admin/settings.php`, `admin/pages/list.php`) — vorher nur `registration_enabled`, `audit_log_retention_days`, `smtp_pass` und `github_token`. `user_permissions_changed` greift jetzt auch beim Anlegen eines neuen Nutzers mit initialen Berechtigungen, nicht nur beim nachträglichen Bearbeiten. Keine Passwörter, Tokens, Reset-Links, TOTP-Secrets oder SMTP-Passwörter werden dabei protokolliert — nur Metadaten (Dateiname, Pfad, Größe, Grund, alt/neu-Werte).

## [0.8.3-alpha] - 2026-06-22

### Fixed

- **Mediathek-Löschen entfernte DB-Eintrag, aber nicht die Datei**: `Media::delete()` baute den Dateipfad als `ESSE_ROOT . '/public' . $media['path']`, obwohl `$media['path']` bereits mit `/public/uploads/...` beginnt (`Media::register()`, `scanUploads()`) — Ergebnis war ein nicht-existierender `.../public/public/uploads/...`-Pfad, `unlink()` lief also nie. Gelöschte Dateien blieben dadurch dauerhaft öffentlich erreichbar. Pfad jetzt korrekt über `ESSE_ROOT . $media['path']` gebildet und per `realpath()` gegen `public/uploads/` abgesichert (gleiches Muster wie `PageRenderer::renderPhp()`). Neuer Integrationstest (`tests/integration/MediaTest.php`) deckt das ab.

### Security

- **Update-Flow umging die Backup-Berechtigungssperre**: `/admin/update` und `/admin/update/run` prüften nur `manage_settings` (Standardrecht der `admin`-Rolle) — ein Admin ohne `manage_backups` konnte darüber trotzdem ein automatisches Backup auslösen lassen (`Updater::createBackup()`, fester Schritt 1 des Update-Flows) und Code/Dateien verändern (`Updater::apply()`). Neue Permission `manage_updates`, Update-Routen erfordern jetzt `forge` oder **beide** Rechte (`manage_updates` UND `manage_backups`). Nav-Links in `admin/layout.php` waren zudem noch auf das alte `manage_settings` für Backups/Updates gegated — korrigiert.
- **Admin konnte sich selbst Forge-nahe Rechte geben**: `php_upload`, `manage_backups` (und jetzt `manage_updates`) waren über `/admin/users/edit/{id}` und `/admin/roles` für jeden Nutzer mit `manage_admins` vergebbar — auch für den eigenen Account. Diese drei Permissions sind jetzt als `Auth::FORGE_ONLY_PERMISSIONS` markiert: nur Forge sieht die Checkboxen/Toggles dafür in der UI, und der Server lehnt entsprechende Vergabe-/Entzugs-Versuche von Nicht-Forge-Admins ab (inkl. Schutz gegen versehentlichen Entzug bestehender Forge-Grants beim Speichern eines Nutzers durch einen Nicht-Forge-Admin).
- **Fehlendes `X-Content-Type-Options: nosniff` bei statischen Dateien (teilweise)**: Neue `Header always set`-Direktive (mod_headers, `<IfModule>`-Guard) in der Root-`.htaccess` setzt `nosniff` für alle über Apache laufenden Antworten (PHP-Routen, 404-Fallbacks). **Bekannte Einschränkung:** Auf dem aktuellen Hosting läuft ein nginx mit eigenem `try_files`-Block, der CSS/JS/Bilder/Uploads direkt vom Datenträger ausliefert und Apache/`.htaccess` für diese Dateitypen komplett umgeht — der Header fehlt dort weiterhin. Erfordert eine nginx-seitige Direktive außerhalb dieses Repos (HestiaCP-Domain-Config bzw. Custom-Directives), bewusst zurückgestellt.

## [0.8.2-alpha] - 2026-06-22

### Security

- **`install/installed.lock` öffentlich lesbar**: Da `install/` kein eigenes `.htaccess` hatte, lieferte die Root-`.htaccess` die Lock-Datei als „existierende Datei" direkt aus (HTTP 200, Installations-Zeitstempel im Klartext) — kein kritisches Secret, aber unnötige Info-Leakage. Neue `install/.htaccess` blockt `installed.lock` sowie literale `.php`-Direktaufrufe (`<FilesMatch>`/`<Files>`, gleiches Muster wie `admin/.htaccess`) — die geroutete `/install`-URL (Erstinstallation bzw. „Already installed"-Seite) bleibt davon unberührt.
- **`local.php` direkt erreichbar**: Lieferte HTTP 200 mit leerem Body (PHP wurde ausgeführt, nur ohne Output) — bei späterem Fehlverhalten oder fehlerhafter PHP-Konfiguration ein Risiko, da die Datei nie über eine Route aufgerufen wird, sondern nur intern von `index.php` per `require` eingebunden wird. Neue `<Files "local.php">`-Direktive in der Root-`.htaccess` blockt Direktzugriff.

## [0.8.1-alpha] - 2026-06-22

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
