<?php
/**
 * @var array          $page
 * @var string         $content
 * @var string         $siteName
 * @var array          $mainMenu
 * @var array          $footMenu
 * @var \EsseBase\Theme $theme
 */
?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page['title'] . ' — ' . $siteName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $theme->assetUrl('css/esse-base.css') ?>">
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-secondary">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/"><?= htmlspecialchars($siteName) ?></a>

        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php foreach ($mainMenu as $item): ?>
                <?php $url = \Esse\Menu::itemUrl($item); ?>
                <?php if (!empty($item['children'])): ?>
                <li class="nav-item dropdown d-flex align-items-center">
                    <a class="nav-link <?= $url === '/' . ltrim($page['slug'], '/') ? 'active' : '' ?>"
                       href="<?= htmlspecialchars($url) ?>"
                       <?= $item['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
                        <?= htmlspecialchars($item['label']) ?>
                    </a>
                    <a class="nav-link px-1 dropdown-toggle dropdown-toggle-split"
                       href="#" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="visually-hidden">Untermenü öffnen</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <?php foreach ($item['children'] as $child): ?>
                        <?php if ($child['type'] === 'header'): ?>
                            <li><h6 class="dropdown-header"><?= htmlspecialchars($child['label']) ?></h6></li>
                        <?php else: ?>
                            <li>
                                <a class="dropdown-item"
                                   href="<?= htmlspecialchars(\Esse\Menu::itemUrl($child)) ?>"
                                   <?= $child['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
                                    <?= htmlspecialchars($child['label']) ?>
                                </a>
                            </li>
                        <?php endif ?>
                        <?php endforeach ?>
                    </ul>
                </li>
                <?php elseif ($item['type'] === 'header'): ?>
                <li class="nav-item">
                    <span class="nav-link text-secondary disabled"><?= htmlspecialchars($item['label']) ?></span>
                </li>
                <?php else: ?>
                <li class="nav-item">
                    <a class="nav-link <?= $url === '/' . ltrim($page['slug'], '/') ? 'active' : '' ?>"
                       href="<?= htmlspecialchars($url) ?>"
                       <?= $item['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
                        <?= htmlspecialchars($item['label']) ?>
                    </a>
                </li>
                <?php endif ?>
                <?php endforeach ?>
            </ul>
        </div>
    </div>
</nav>

<!-- Content -->
<main class="container py-5">
    <div class="esse-content">
        <?= $content ?>
    </div>
</main>

<!-- Footer -->
<?php if ($footMenu || !empty($siteName)): ?>
<footer class="border-top border-secondary py-4 mt-4">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-6">
                <span class="text-secondary small">&copy; <?= date('Y') ?> <?= htmlspecialchars($siteName) ?></span>
            </div>
            <?php if ($footMenu): ?>
            <div class="col-md-6 text-md-end">
                <?php foreach ($footMenu as $item): ?>
                <?php if ($item['type'] !== 'header'): ?>
                <a href="<?= htmlspecialchars(\Esse\Menu::itemUrl($item)) ?>"
                   class="text-secondary text-decoration-none small ms-3"
                   <?= $item['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
                    <?= htmlspecialchars($item['label']) ?>
                </a>
                <?php endif ?>
                <?php endforeach ?>
            </div>
            <?php endif ?>
        </div>
    </div>
</footer>
<?php endif ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
