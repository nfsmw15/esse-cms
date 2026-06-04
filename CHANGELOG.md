# Changelog

All notable changes to ESSE CMS will be documented in this file.

## [Unreleased]

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
- Repo-based plugin/theme install (apt-style) not yet implemented
- File manager (browse existing uploads) not yet implemented
- esse-download, esse-gallery and other plugins not yet ported
