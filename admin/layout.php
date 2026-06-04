<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Admin') ?> — ESSE CMS</title>
    <link rel="stylesheet" href="/public/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/public/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <style>
        :root { --sidebar-w: 240px; }
        body  { background: #0d0d0d; color: #e0e0e0; }

        /* Sidebar */
        #sidebar {
            width: var(--sidebar-w);
            min-height: 100vh;
            background: #111;
            border-right: 1px solid #1e1e1e;
            position: fixed;
            top: 0; left: 0;
            display: flex;
            flex-direction: column;
        }
        #sidebar .brand {
            padding: 1.25rem 1.5rem;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: .1em;
            border-bottom: 1px solid #1e1e1e;
            color: #fff;
        }
        #sidebar .brand small {
            display: block;
            font-size: .65rem;
            font-weight: 400;
            letter-spacing: .05em;
            color: #555;
        }
        #sidebar nav { padding: .75rem 0; flex: 1; }
        #sidebar nav a {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .5rem 1.5rem;
            color: #999;
            text-decoration: none;
            font-size: .875rem;
            border-left: 2px solid transparent;
            transition: color .15s, background .15s;
        }
        #sidebar nav a:hover  { color: #fff; background: #1a1a1a; }
        #sidebar nav a.active { color: #fff; border-left-color: #0d6efd; background: #1a1a1a; }
        #sidebar nav .nav-section {
            padding: .75rem 1.5rem .25rem;
            font-size: .65rem;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: #444;
        }
        #sidebar .user-info {
            padding: .75rem 1rem;
            border-top: 1px solid #1e1e1e;
        }
        #sidebar .user-menu {
            background: transparent;
            border: none;
            color: #888;
            font-size: .8rem;
            text-align: left;
            display: flex;
            align-items: center;
            gap: .5rem;
            padding: .4rem .75rem;
            border-radius: .375rem;
            transition: background .15s, color .15s;
        }
        #sidebar .user-menu:hover { background: #1a1a1a; color: #ccc; }
        #sidebar .user-menu::after { margin-left: auto; }
        #sidebar .user-menu span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }
        #sidebar .dropdown-menu {
            background: #1a1a1a;
            border-color: #2d2d2d;
            font-size: .85rem;
        }
        #sidebar .dropdown-item { color: #adb5bd; padding: .45rem 1rem; }
        #sidebar .dropdown-item:hover { background: #2d2d2d; color: #fff; }
        #sidebar .dropdown-item form button { background: none; border: none; width: 100%; text-align: left; color: #adb5bd; padding: 0; }
        #sidebar .dropdown-item form button:hover { color: #fff; }
        #sidebar .dropdown-divider { border-color: #2d2d2d; }

        /* Main */
        #main {
            margin-left: var(--sidebar-w);
            min-height: 100vh;
        }
        #topbar {
            background: #111;
            border-bottom: 1px solid #1e1e1e;
            padding: .75rem 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        #topbar h1 { font-size: 1.1rem; font-weight: 600; margin: 0; color: #fff; }
        .content { padding: 1.75rem; }

        /* Cards / tables */
        .card { background: #1a1a1a; border: 1px solid #2d2d2d; }
        .card-header { background: #161616; border-bottom: 1px solid #2d2d2d; }
        .table { color: #e0e0e0; }
        .table td, .table th { border-color: #2d2d2d; }
        .table tbody tr:hover { background: #1f1f1f; }
        .form-control, .form-select {
            background: #111; border-color: #333; color: #e0e0e0;
        }
        .form-control:focus, .form-select:focus {
            background: #111; border-color: #555; color: #e0e0e0; box-shadow: none;
        }
        .badge-forge  { background: #7c3aed; }
        .badge-admin  { background: #0d6efd; }
        .badge-editor { background: #0891b2; }
        .badge-author { background: #059669; }
        .badge-member { background: #374151; }
    </style>
    <?= $extraHead ?? '' ?>
</head>
<body>

<div id="sidebar">
    <div class="brand">
        ESSE CMS <small>forge your web.</small>
        <a href="/"
           class="text-decoration-none"
           style="font-size:.7rem;font-weight:400;letter-spacing:.02em;color:#555;display:block;margin-top:.15rem">
            <i class="bi bi-arrow-left" style="font-size:.65rem"></i> Zur Website
        </a>
    </div>
    <nav>
        <?php $canContent = \Esse\Auth::can('manage_content'); ?>
        <div class="nav-section">Inhalt</div>
        <a href="/admin" class="<?= ($activeNav ?? '') === 'dashboard' ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <?php if ($canContent): ?>
        <a href="/admin/pages" class="<?= ($activeNav ?? '') === 'pages' ? 'active' : '' ?>">
            <i class="bi bi-file-earmark-text"></i> Seiten
        </a>
        <a href="/admin/menus" class="<?= ($activeNav ?? '') === 'menus' ? 'active' : '' ?>">
            <i class="bi bi-list-nested"></i> Menüs
        </a>
        <?php endif ?>

        <?php
        // Plugin-registered nav items
        $pluginNavItems = \Esse\Hooks::filter('admin.nav', []);
        if ($pluginNavItems):
        ?>
        <div class="nav-section">Plugins</div>
        <?php foreach ($pluginNavItems as $navItem): ?>
        <a href="<?= htmlspecialchars($navItem['url']) ?>"
           class="<?= ($activeNav ?? '') === ($navItem['active'] ?? '') ? 'active' : '' ?>">
            <?php
            $navIcon = $navItem['icon'] ?? 'puzzle';
            if (str_contains($navIcon, ' ')) {
                // Full CSS class with space (e.g. 'bi bi-newspaper') → use directly
                echo '<i class="' . htmlspecialchars($navIcon) . '"></i>';
            } else {
                // Strip known pack prefixes for backward compat (e.g. 'bi-newspaper' → 'newspaper')
                $iconName = preg_replace('/^(bi|ph|ti|lucide|fa|ri)-/', '', $navIcon);
                echo \Esse\Ui::icon($iconName);
            }
            ?>
            <?= htmlspecialchars($navItem['label']) ?>
        </a>
        <?php endforeach ?>
        <?php endif ?>

        <div class="nav-section">System</div>
        <?php if (\Esse\Auth::can('manage_users')): ?>
        <a href="/admin/users" class="<?= ($activeNav ?? '') === 'users' ? 'active' : '' ?>">
            <i class="bi bi-people"></i> Benutzer
        </a>
        <?php endif ?>
        <?php if (\Esse\Auth::can('manage_admins') || \Esse\Auth::meetsRole('forge')): ?>
        <a href="/admin/roles" class="<?= ($activeNav ?? '') === 'roles' ? 'active' : '' ?>">
            <i class="bi bi-shield-lock"></i> Rollen & Rechte
        </a>
        <?php endif ?>
        <?php if (\Esse\Auth::can('manage_settings')): ?>
        <a href="/admin/settings" class="<?= ($activeNav ?? '') === 'settings' ? 'active' : '' ?>">
            <i class="bi bi-gear"></i> Einstellungen
        </a>
        <?php endif ?>
        <?php if (\Esse\Auth::can('manage_plugins')): ?>
        <a href="/admin/plugins" class="<?= ($activeNav ?? '') === 'plugins' ? 'active' : '' ?>">
            <i class="bi bi-puzzle"></i> Plugins
        </a>
        <?php endif ?>
        <?php if (\Esse\Auth::can('manage_themes')): ?>
        <a href="/admin/themes" class="<?= ($activeNav ?? '') === 'themes' ? 'active' : '' ?>">
            <i class="bi bi-palette"></i> Themes
        </a>
        <?php endif ?>
        <?php if (\Esse\Auth::can('manage_settings') || \Esse\Auth::meetsRole('forge')): ?>
        <a href="/admin/iconpacks" class="<?= ($activeNav ?? '') === 'iconpacks' ? 'active' : '' ?>">
            <i class="bi bi-emoji-smile"></i> Icon-Packs
        </a>
        <?php endif ?>
        <?php if (\Esse\Auth::can('view_logs')): ?>
        <a href="/admin/logs" class="<?= ($activeNav ?? '') === 'logs' ? 'active' : '' ?>">
            <i class="bi bi-terminal"></i> Logs
        </a>
        <?php endif ?>
        <?php if (\Esse\Auth::can('manage_settings')): ?>
        <a href="/admin/backup" class="<?= ($activeNav ?? '') === 'backup' ? 'active' : '' ?>">
            <i class="bi bi-shield-check"></i> Backups
        </a>
        <a href="/admin/update" class="<?= ($activeNav ?? '') === 'update' ? 'active' : '' ?>">
            <i class="bi bi-cloud-arrow-up"></i> System-Update
        </a>
        <?php endif ?>
    </nav>
    <div class="user-info">
        <div class="dropdown">
            <button class="btn user-menu dropdown-toggle w-100" type="button"
                    data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                <i class="bi bi-person-circle"></i>
                <span><?= htmlspecialchars(\Esse\Auth::user()['display_name'] ?? '') ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow w-100">
                <li>
                    <a class="dropdown-item" href="/profil">
                        <i class="bi bi-person me-2"></i>Profil
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="/">
                        <i class="bi bi-arrow-left me-2"></i>Zur Website
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="post" action="/admin/logout">
                        <input type="hidden" name="_csrf" value="<?= \Esse\Auth::csrfToken() ?>">
                        <button class="dropdown-item" type="submit">
                            <i class="bi bi-box-arrow-right me-2"></i>Abmelden
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</div>

<div id="main">
    <div id="topbar">
        <h1><?= htmlspecialchars($pageTitle ?? 'Admin') ?></h1>
        <div><?= $topbarRight ?? '' ?></div>
    </div>
    <div class="content">
        <?php if (!empty($flash)): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type'] ?? 'info') ?> alert-dismissible fade show">
            <?= htmlspecialchars($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif ?>

        <?= $content ?? '' ?>
    </div>
</div>

<?= isset($extraScripts) ? '<script src="/public/vendor/summernote/jquery.min.js"></script>' : '' ?>
<script src="/public/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<?= $extraScripts ?? '' ?>
</body>
</html>
