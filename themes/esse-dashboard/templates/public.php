<?php
/**
 * Minimal layout for public pages (not logged in).
 * No sidebar — just header, content and footer.
 *
 * @var array                $page
 * @var string               $content
 * @var string               $siteName
 * @var array                $footMenu
 * @var \EsseDashboard\Theme  $theme
 */
?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page['title'] . ' — ' . $siteName) ?></title>
    <link rel="stylesheet" href="/public/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $theme->assetUrl('css/esse-dashboard.css') ?>">
</head>
<body style="background:var(--bg)">

<!-- Minimal topbar -->
<nav style="background:var(--sidebar-bg);border-bottom:1px solid var(--border);padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between">
    <a href="/" class="text-white text-decoration-none fw-bold"><?= htmlspecialchars($siteName) ?></a>
    <a href="/admin/login" class="btn btn-sm btn-outline-secondary">
        Anmelden
    </a>
</nav>

<!-- Content -->
<main class="container py-5" style="max-width:860px">
    <?php if (!empty($page['icon']) || $page['title']): ?>
    <h1 class="mb-4">
        <?php if (!empty($page['icon'])): ?>
        <i class="<?= htmlspecialchars($page['icon']) ?> me-2"></i>
        <?php endif ?>
        <?= htmlspecialchars($page['title']) ?>
    </h1>
    <?php endif ?>
    <div class="esse-content">
        <?= $content ?>
    </div>
</main>

<!-- Footer -->
<?php if ($footMenu):
    $groups = [];
    $current = ['header' => null, 'links' => []];
    foreach ($footMenu as $item) {
        if ($item['type'] === 'header') {
            if ($current['header'] !== null || !empty($current['links'])) $groups[] = $current;
            $current = ['header' => $item['label'], 'links' => $item['children'] ?? []];
        } else {
            $current['links'][] = $item;
        }
    }
    if ($current['header'] !== null || !empty($current['links'])) $groups[] = $current;
?>
<footer class="w-100 py-4" style="border-top:1px solid var(--border)">
    <div class="container d-flex flex-wrap justify-content-center gap-5" style="max-width:860px">
        <?php foreach ($groups as $group): ?>
        <div>
            <?php if ($group['header'] !== null): ?>
            <p class="text-white small fw-semibold mb-1"><?= htmlspecialchars($group['header']) ?></p>
            <hr class="border-secondary mt-0 mb-2">
            <?php endif ?>
            <?php foreach ($group['links'] as $link): ?>
            <?php if ($link['type'] === 'header'): ?>
            <p class="text-secondary small mb-1"><?= htmlspecialchars($link['label']) ?></p>
            <?php else: ?>
            <div>
                <a href="<?= htmlspecialchars(\Esse\Menu::itemUrl($link)) ?>"
                   class="text-secondary small text-decoration-none"
                   <?= $link['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
                    <?= htmlspecialchars($link['label']) ?>
                </a>
            </div>
            <?php endif ?>
            <?php endforeach ?>
        </div>
        <?php endforeach ?>
    </div>
</footer>
<?php endif ?>

<script src="/public/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
