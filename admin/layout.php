<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Admin') ?> — ESSE CMS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11/font/bootstrap-icons.css">
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
            padding: 1rem 1.5rem;
            border-top: 1px solid #1e1e1e;
            font-size: .8rem;
            color: #666;
        }
        #sidebar .user-info strong { color: #bbb; display: block; }

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
    <div class="brand">ESSE CMS <small>forge your web.</small></div>
    <nav>
        <div class="nav-section">Inhalt</div>
        <a href="/admin" class="<?= ($activeNav ?? '') === 'dashboard' ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a href="/admin/pages" class="<?= ($activeNav ?? '') === 'pages' ? 'active' : '' ?>">
            <i class="bi bi-file-earmark-text"></i> Seiten
        </a>
        <a href="/admin/menus" class="<?= ($activeNav ?? '') === 'menus' ? 'active' : '' ?>">
            <i class="bi bi-list-nested"></i> Menüs
        </a>

        <div class="nav-section">System</div>
        <a href="/admin/users" class="<?= ($activeNav ?? '') === 'users' ? 'active' : '' ?>">
            <i class="bi bi-people"></i> Benutzer
        </a>
        <a href="/admin/plugins" class="<?= ($activeNav ?? '') === 'plugins' ? 'active' : '' ?>">
            <i class="bi bi-puzzle"></i> Plugins
        </a>
        <a href="/admin/themes" class="<?= ($activeNav ?? '') === 'themes' ? 'active' : '' ?>">
            <i class="bi bi-palette"></i> Themes
        </a>
        <a href="/admin/settings" class="<?= ($activeNav ?? '') === 'settings' ? 'active' : '' ?>">
            <i class="bi bi-gear"></i> Einstellungen
        </a>
        <?php if (\Esse\Auth::can('view_logs')): ?>
        <a href="/admin/logs" class="<?= ($activeNav ?? '') === 'logs' ? 'active' : '' ?>">
            <i class="bi bi-terminal"></i> Logs
        </a>
        <?php endif ?>
    </nav>
    <div class="user-info">
        <strong><?= htmlspecialchars(\Esse\Auth::user()['display_name'] ?? '') ?></strong>
        <span class="badge badge-<?= \Esse\Auth::role() ?> rounded-pill" style="font-size:.65rem">
            <?= ucfirst(\Esse\Auth::role()) ?>
        </span>
        &nbsp;
        <a href="/admin/logout" class="text-secondary text-decoration-none" style="font-size:.75rem">
            <i class="bi bi-box-arrow-right"></i> Abmelden
        </a>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3/dist/js/bootstrap.bundle.min.js"></script>
<?= $extraScripts ?? '' ?>
</body>
</html>
