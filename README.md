# ESSE CMS

> forge your web.

A lightweight PHP 8 CMS for secure pages, roles and plugins.

[![Release](https://img.shields.io/github/v/release/nfsmw15/esse-cms?label=release&color=blue&include_prereleases)](https://github.com/nfsmw15/esse-cms/releases)
[![License](https://img.shields.io/badge/license-AGPL--3.0-green)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777bb4)](https://www.php.net/)

ESSE CMS is a fresh-start CMS for demanding admins who want more control than a click-and-play system offers — without building everything from scratch. Fully free and open source (AGPL-3.0).

---

## Why ESSE?

Most capable CMS platforms require a commercial license for non-personal use. ESSE is different: fully FOSS, no license fees, no SaaS lock-in. The AGPL-3.0 license ensures that anyone running a modified version as a web service must publish their changes.

**The name:** *Esse* is German for the hearth of a forge — and Latin for "to be." The claim: *forge your web.*

---

## Features

- **Passkeys & 2FA built into core** — passwordless WebAuthn/FIDO2 login (Touch ID, Windows Hello, security keys) plus TOTP-based two-factor authentication with backup codes — no plugins required
- **Code-based routing** — no URL table in the database
- **Hook/event system** — `Hooks::fire`, `Hooks::on`, `Hooks::filter`
- **Plugin system** — apt-style repos (GitHub-based), install/update/remove
- **Theme system** — framework-agnostic frontend themes, Bootstrap 5 admin panel
- **Custom PHP pages** — upload your own PHP files as first-class pages (admin permission required)
- **Configurable page targets** — homepage, post-login page, logout page and error page can point to CMS, core or plugin pages
- **Role system** — Forge / Admin / Editor / Author / Member / Guest + custom roles
- **Granular permissions** — e.g. `php_upload` is a separate right, not automatic for admins
- **SSE-based updater** — live terminal output during updates
- **Web installer** — sets up DB connection and first Forge account

---

## Role System

| Role | Description |
|---|---|
| `Forge` | Site operator — cannot be removed or demoted by anyone else |
| `Admin` | Full access except Forge management; PHP upload is an optional permission |
| `Editor` | Manage content, no system settings |
| `Author` | Own content only |
| `Member` | Logged in — sees member-only content |
| `Guest` | Not logged in — public content only |

Custom roles with granular permissions can be created. Default roles cannot be deleted.

---

## Content Visibility

Each page has a visibility level:
- `public` — everyone (Guest+)
- `guest_only` — visitors who are not logged in
- `registered` — logged-in users
- `roles` — selected roles only

Global page targets can be configured under Admin → Einstellungen → Seitenzuordnung. Standard pages such as `/login`, `/registrieren` and `/profil` are available alongside CMS pages and plugin-registered pages.

---

## Plugin Repos

- **GitHub-based discovery** — plugins and themes are discovered through `esse-plugin` / `esse-theme` repository topics
- **Official and community channels** — trusted repositories can be configured separately from community sources
- **Release-based installs and updates** — available plugins and themes are installed from repository releases

---

## Directory Structure

```
esse-cms/
├── core/           PHP core (Router, Hooks, Container, Auth, DB, Plugin, Theme)
├── plugins/        Installed plugins
├── themes/         Installed themes (esse-base, esse-blank; more can be installed)
├── storage/        Cache, uploads, backups — not web-accessible
├── pages/          Custom PHP pages uploaded by admins — not directly web-accessible
├── admin/          Admin panel
├── install/        Web installer
├── config/         DB config and settings — not web-accessible
├── public/         Public assets (CSS, JS, images)
└── index.php       Entry point
```

Sensitive directories (`core`, `config`, `storage`, `pages`, `plugins`) are protected via `.htaccess` and are never accessible directly — all requests go through `index.php`.

---

## Security

Security is a first-class design goal in ESSE — not an afterthought.

**Architecture:**
- All requests are routed through `index.php` — no direct file access to PHP logic
- Custom PHP pages are never accessed directly; they are included by the router after auth and permission checks
- Sensitive directories (`core`, `config`, `storage`, `pages`, `plugins`) are protected via `.htaccess`
- Uploaded files (non-PHP) are served through a controller, not directly from disk

**Authentication:**
- Self-registered accounts must verify their e-mail address (time-limited link, resend supported) before they can log in — admin-created accounts are exempt, since the admin already vouches for the address
- Optional admin approval step for new registrations (off by default) — for installations handling sensitive data, an admin can require manual sign-off on every new account in addition to e-mail verification, toggled live in Settings
- Optional enforced TOTP/Passkey (off by default) — Settings can require every account to have TOTP-or-Passkey, or Passkey only (the strictest tier); a non-compliant account is forced into setup right after a correct password, before any session exists
- Configurable password policy — custom minimum length + required character-class count + optional password history (reject reuse of the last N passwords) + optional limit on consecutive sequential characters (e.g. "abcd", "4321"), or a built-in "BSI recommendation" mode (Germany's federal infosec agency) that mirrors its official three-tier guidance, including a relaxed tier for accounts with active 2FA/Passkey
- Live password-requirements checklist on registration/profile/reset forms, updating on every keystroke — no theme-CSS dependency, works regardless of the active frontend theme
- Optional two-factor authentication (TOTP / authenticator app) with one-time backup codes — classic second factor on top of the password, including a self-hosted, pure-PHP QR code generator (no JS vendoring, no CDN)
- Passwordless login via Passkeys (WebAuthn/FIDO2, discoverable credentials) — a standalone login method that replaces password *and* TOTP entirely (Touch ID, Windows Hello, security keys)
- Both are optional and per-user; WebAuthn cryptography (attestation/assertion verification, CBOR decoding) is handled by the vendored `report-uri/passkeys-php` library rather than custom crypto code
- IP-based rate limiting on login, 2FA verification and password-reset requests — persisted in the database, so it survives a cleared session cookie
- Session cookies are `HttpOnly`, `SameSite=Lax` and automatically `Secure` on HTTPS installations
- Core responses send browser hardening headers, including CSP, frame protection, referrer policy, permissions policy and `X-Content-Type-Options`
- Inline JavaScript and inline CSS have been moved to static assets plus JSON configuration blocks; the default CSP uses `script-src 'self'` and `style-src 'self'` without `unsafe-inline`

**Private path (recommended for VPS / HestiaCP):**
The installer optionally stores `config/` and `storage/` outside the webroot entirely — in a directory like `~/private/esse/` that is never reachable via HTTP. No `.htaccess` misconfiguration can expose DB credentials or uploads.

```
/home/user/
├── public_html/        ← webroot (HTTP-accessible)
│   └── index.php
└── private/esse/       ← never reachable via HTTP
    ├── config/
    └── storage/
```

**Permissions:**
- PHP upload is a separate, explicitly granted permission — not automatic for admins
- Promoting a user to Forge role requires confirming a risk dialog
- Installer is locked after first run (`install/installed.lock`)

**Audit log (`/admin/logs`, `view_logs` permission):**
- Tracks security-relevant events: logins (success/failure/lockout), 2FA/passkey enrollment changes, password resets, user management (creation, role changes, additional permissions, activation/deactivation), role management (created/deleted, per-role permission changes), own profile changes (password, email), PHP/HTML page uploads, and plugin management (install/update/enable/disable/uninstall)
- GDPR/DSGVO-compliant: stored under legitimate interest (Art. 6(1)(f) GDPR) for security purposes, with entries auto-deleted after a configurable retention period (default 90 days, Admin → Settings)

---

## Tests

A minimal, dependency-free test runner lives in `tests/` (no Composer/PHPUnit required, in line with the project's no-external-dependencies philosophy):

```bash
php tests/run.php
```

Each `tests/*Test.php` file returns an array of `description => closure` and is executed by `tests/run.php`. Currently covers version comparison (`Updater::isNewer`), TOTP code generation/verification (`Totp`), the CAPTCHA challenge/honeypot logic (`Captcha`), CSRF token generation/validation (`Auth::csrfToken`/`verifyCsrf`), role hierarchy and permission checks (`Auth::meetsRole`/`can`/`canAny`), the hook system (`Hooks`), the core DB schema (`Schema::tables`), and the audit log (`AuditLog`).

### Integration tests

`tests/integration/` spins up a real PHP built-in server against a dedicated `esse_test` database and drives it with a small cURL-based HTTP client, covering full request/response cycles (login, lockout, CSRF, page visibility).

One-time setup (creates the `esse_test` database and user):

```bash
sudo mysql < tests/integration/setup-db.sql
```

Then run:

```bash
php tests/integration/run.php
```

This resets and seeds the test database, starts a temporary server on `127.0.0.1:8089`, runs all `tests/integration/*Test.php` files, and shuts the server down again. It does not touch the local `config/`/`local.php`.

---

## Tech Stack

- PHP 8.1+
- MySQL / MariaDB
- Bootstrap 5 (admin panel only; frontend themes are framework-agnostic)
- No Composer dependency required for core

---

## License

AGPL-3.0 — see [LICENSE](LICENSE)
