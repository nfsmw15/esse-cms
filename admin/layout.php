<?php
$brandName   = 'ESSE CMS';
$brandSlogan = '';
if (defined('ESSE_DB_NAME')) {
    $ts        = \Esse\DB::table('settings');
    $brandRows = array_column(
        \Esse\DB::fetchAll("SELECT `key`, `value` FROM `{$ts}` WHERE `key` IN ('site_name', 'site_slogan')"),
        'value', 'key'
    );
    $brandName   = $brandRows['site_name']   ?? $brandName;
    $brandSlogan = $brandRows['site_slogan'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Admin') ?> — ESSE CMS</title>
    <link rel="stylesheet" href="/public/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/public/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/public/assets/css/admin.css">
    <?= $extraHead ?? '' ?>
</head>
<body>

<div id="sidebar">
    <div class="brand">
        <?= htmlspecialchars($brandName) ?><?php if ($brandSlogan !== ''): ?> <small><?= htmlspecialchars($brandSlogan) ?></small><?php endif ?>
        <a href="/"
           class="text-decoration-none admin-site-link">
            <i class="bi bi-arrow-left admin-site-link-icon"></i> Zur Website
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
        <?php if (\Esse\Auth::canAny(['manage_files', 'manage_content'])): ?>
        <a href="/admin/media" class="<?= ($activeNav ?? '') === 'media' ? 'active' : '' ?>">
            <i class="bi bi-images"></i> Mediathek
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
        <a href="/admin/user-fields" class="<?= ($activeNav ?? '') === 'user-fields' ? 'active' : '' ?>">
            <i class="bi bi-input-cursor-text"></i> Profilfelder
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

<?php if (!empty($extraScriptConfig ?? [])): ?>
<?php foreach ($extraScriptConfig as $id => $data): ?>
<script type="application/json" id="<?= htmlspecialchars((string) $id) ?>"><?= json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endforeach ?>
<?php endif ?>
<script src="/public/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="/public/assets/js/admin-common.js"></script>
<?php foreach (($extraScriptFiles ?? []) as $src): ?>
<script src="<?= htmlspecialchars($src) ?>"></script>
<?php endforeach ?>
</body>
</html>
