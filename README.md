# ESSE CMS

> forge your web.

A lightweight PHP 8 CMS for secure pages, roles and plugins.

ESSE CMS is a fresh-start CMS for demanding admins who want more control than WordPress offers — without building everything from scratch. Fully free and open source (AGPL-3.0).

---

## Why ESSE?

Most capable CMS platforms charge for commercial use (Craft, Statamic, Kirby). ESSE is different: fully FOSS, no license fees, no SaaS lock-in. The AGPL-3.0 license ensures that anyone running a modified version as a web service must publish their changes.

**The name:** *Esse* is German for the hearth of a forge — and Latin for "to be." The claim: *forge your web.*

---

## Features (planned)

- **Code-based routing** — no URL table in the database
- **Hook/event system** — `Hooks::fire`, `Hooks::on`, `Hooks::filter`
- **Plugin system** — apt-style repos (GitHub-based), install/update/remove
- **Theme system** — framework-agnostic frontend themes, Bootstrap 5 admin panel
- **Custom PHP pages** — upload your own PHP files as first-class pages (admin permission required)
- **Role system** — Forge / Admin / Editor / Author / Member / Guest + custom roles
- **Granular permissions** — e.g. `php_upload` is a separate right, not automatic for admins
- **SSE-based updater** — live terminal output during updates (ported from Easy 2)
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

- All requests are routed through `index.php` — no direct file access to PHP logic
- Custom PHP pages are never accessed directly; they are included by the router after auth checks
- `config/` holds no web-accessible files
- Uploaded files (non-PHP) are served through a controller, not directly
- PHP upload is a separate, explicitly granted permission
- Installer is locked after first run

---

## Tech Stack

- PHP 8.1+
- MySQL / MariaDB
- Bootstrap 5 (admin panel only; frontend themes are framework-agnostic)
- No Composer dependency required for core

---

## License

AGPL-3.0 — see [LICENSE](LICENSE)
