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

## Features (planned)

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
- `members` — logged-in users (Member+)
- `admin` — admins only
- or any custom role

Global page targets can be configured under Admin → Einstellungen → Seitenzuordnung. Standard pages such as `/login`, `/registrieren` and `/profil` are available alongside CMS pages and plugin-registered pages.

---

## Plugin Repos

- **Official Esse repo** — trusted by default
- **Community repos** — can be added with a warning; plugins from unverified repos show a warning on install

---

## Directory Structure

```
esse-cms/
├── core/           PHP core (Router, Hooks, Container, Auth, DB, Plugin, Theme)
├── plugins/        Installed plugins
├── themes/         Installed themes (esse-base, esse-dashboard, esse-blank)
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
- Optional two-factor authentication (TOTP / authenticator app) with one-time backup codes — classic second factor on top of the password, including a self-hosted, pure-PHP QR code generator (no JS vendoring, no CDN)
- Passwordless login via Passkeys (WebAuthn/FIDO2, discoverable credentials) — a standalone login method that replaces password *and* TOTP entirely (Touch ID, Windows Hello, security keys)
- Both are optional and per-user; WebAuthn cryptography (attestation/assertion verification, CBOR decoding) is handled by the vendored `report-uri/passkeys-php` library rather than custom crypto code
- Session cookies are `HttpOnly`, `SameSite=Lax` and automatically `Secure` on HTTPS installations
- Core responses send browser hardening headers, including CSP, frame protection, referrer policy, permissions policy and `X-Content-Type-Options`
- Inline JavaScript has been moved to static assets plus JSON configuration blocks; the default CSP uses `script-src 'self'` without `unsafe-inline`

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

---

## Tech Stack

- PHP 8.1+
- MySQL / MariaDB
- Bootstrap 5 (admin panel only; frontend themes are framework-agnostic)
- No Composer dependency required for core

---

## License

AGPL-3.0 — see [LICENSE](LICENSE)
