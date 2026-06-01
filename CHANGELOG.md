# Changelog

All notable changes to ESSE CMS will be documented in this file.

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
