<?php
/**
 * @var array               $page
 * @var string              $content
 * @var string              $siteName
 * @var array               $sidebarMenu
 * @var array               $footMenu
 * @var \EsseDashboard\Theme $theme
 */

$currentSlug = $page['slug'] ?? '';
?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page['title'] . ' — ' . $siteName) ?></title>
    <link rel="stylesheet" href="/public/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/public/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= $theme->assetUrl('css/esse-dashboard.css') ?>">
</head>
<body>

<!-- Mobile overlay -->
<div id="sidebar-overlay" onclick="toggleSidebar()"></div>

<!-- ── Sidebar ── -->
<div id="dash-sidebar">
    <div class="brand">
        <a href="/" class="text-decoration-none text-white"><?= htmlspecialchars($siteName) ?></a>
        <small>forge your web.</small>
    </div>

    <nav>
        <?php foreach ($sidebarMenu as $item):
            $url        = \Esse\Menu::itemUrl($item);
            $hasChildren = !empty($item['children']);
            $isActive   = $currentSlug === ltrim($url, '/');
        ?>

        <?php if ($item['type'] === 'header'): ?>
            <div class="nav-section"><?= htmlspecialchars($item['label']) ?></div>

        <?php elseif ($hasChildren): ?>
            <div class="dash-has-children <?= $isActive ? 'open' : '' ?>">
                <a href="<?= htmlspecialchars($url) ?>"
                   onclick="toggleSubMenu(event, this.parentElement)"
                   <?= $item['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
                    <?= htmlspecialchars($item['label']) ?>
                    <span class="dash-arrow"><i class="bi bi-chevron-right"></i></span>
                </a>
                <div class="dash-sub">
                    <?php foreach ($item['children'] as $child): ?>
                    <?php if ($child['type'] === 'header'): ?>
                        <div class="nav-section"><?= htmlspecialchars($child['label']) ?></div>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars(\Esse\Menu::itemUrl($child)) ?>"
                           class="<?= $currentSlug === ltrim(\Esse\Menu::itemUrl($child), '/') ? 'active' : '' ?>"
                           <?= $child['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
                            <?= htmlspecialchars($child['label']) ?>
                        </a>
                    <?php endif ?>
                    <?php endforeach ?>
                </div>
            </div>

        <?php else: ?>
            <a href="<?= htmlspecialchars($url) ?>"
               class="<?= $isActive ? 'active' : '' ?>"
               <?= $item['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
                <?php if (!empty($item['icon'])): ?><i class="<?= htmlspecialchars($item['icon']) ?>"></i><?php endif ?>
                <?= htmlspecialchars($item['label']) ?>
            </a>
        <?php endif ?>

        <?php endforeach ?>
    </nav>

    <!-- Sidebar footer: user info or footer links -->
    <div class="sidebar-footer">
        <?php if (\Esse\Auth::check()): ?>
        <div class="d-flex align-items-center gap-2 mb-2">
            <i class="bi bi-person-circle text-secondary"></i>
            <span class="text-secondary small"><?= htmlspecialchars(\Esse\Auth::user()['display_name'] ?? '') ?></span>
        </div>
        <div class="d-flex gap-2">
            <a href="/profil" class="text-secondary small text-decoration-none">
                <i class="bi bi-gear"></i> Profil
            </a>
            <form method="post" action="/abmelden" class="d-inline ms-auto">
                <input type="hidden" name="_csrf" value="<?= \Esse\Auth::csrfToken() ?>">
                <button class="btn btn-link p-0 text-secondary small text-decoration-none">
                    <i class="bi bi-box-arrow-right"></i> Abmelden
                </button>
            </form>
        </div>
        <?php else: ?>
        <a href="/admin/login" class="text-secondary small text-decoration-none">
            <i class="bi bi-person me-1"></i>Anmelden
        </a>
        <?php endif ?>
    </div>
</div>

<!-- ── Topbar ── -->
<div id="dash-topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn btn-link p-0 text-secondary d-lg-none" onclick="toggleSidebar()" style="font-size:1.2rem">
            <i class="bi bi-list"></i>
        </button>
        <h1>
            <?php if (!empty($page['icon'])): ?><i class="<?= htmlspecialchars($page['icon']) ?> me-2"></i><?php endif ?>
            <?= htmlspecialchars($page['title']) ?>
        </h1>
    </div>
    <?php if (\Esse\Auth::meetsRole('author')): ?>
    <a href="/admin" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-speedometer2"></i> Admin
    </a>
    <?php endif ?>
</div>

<!-- ── Content ── -->
<div id="dash-main">
    <div class="dash-content">
        <div class="esse-content">
            <?= $content ?>
        </div>

        <?php if ($footMenu): ?>
        <footer class="mt-5 pt-3 border-top border-secondary">
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <span class="text-secondary small">&copy; <?= date('Y') ?> <?= htmlspecialchars($siteName) ?></span>
                <?php foreach ($footMenu as $item): ?>
                <?php if ($item['type'] !== 'header'): ?>
                <a href="<?= htmlspecialchars(\Esse\Menu::itemUrl($item)) ?>"
                   class="text-secondary small text-decoration-none"
                   <?= $item['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
                    <?= htmlspecialchars($item['label']) ?>
                </a>
                <?php endif ?>
                <?php endforeach ?>
            </div>
        </footer>
        <?php endif ?>
    </div>
</div>

<script src="/public/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() {
    document.getElementById('dash-sidebar').classList.toggle('open');
    document.getElementById('sidebar-overlay').classList.toggle('show');
}

function toggleSubMenu(e, el) {
    // Only toggle if item type is 'header' or no real URL
    if (el.querySelector('a').getAttribute('href') === '#') {
        e.preventDefault();
        el.classList.toggle('open');
    }
}

// Auto-open sub-menu if a child is active
document.querySelectorAll('.dash-has-children').forEach(el => {
    if (el.querySelector('.dash-sub a.active')) {
        el.classList.add('open');
    }
});
</script>
</body>
</html>
