<?php
/**
 * @var array          $page
 * @var string         $content
 * @var string         $siteName
 * @var array          $mainMenu
 * @var array          $footMenu
 * @var \EsseBase\Theme $theme
 */
$code    = (int) ($page['error_code'] ?? 404);
$title   = $page['error_title']   ?? 'Fehler';
$message = $page['error_message'] ?? '';
?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $code ?> — <?= htmlspecialchars($title) ?> — <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/public/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $theme->assetUrl('css/esse-base.css') ?>">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-secondary">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/"><?= htmlspecialchars($siteName) ?></a>
        <div class="navbar-nav ms-auto">
            <?php foreach ($mainMenu as $item): ?>
            <?php if ($item['type'] !== 'header' && empty($item['children'])): ?>
            <a class="nav-link" href="<?= htmlspecialchars(\Esse\Menu::itemUrl($item)) ?>"
               <?= $item['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
                <?= htmlspecialchars($item['label']) ?>
            </a>
            <?php endif ?>
            <?php endforeach ?>
        </div>
    </div>
</nav>

<main class="container py-5 text-center" style="min-height:60vh;display:flex;align-items:center;justify-content:center">
    <div>
        <div class="display-1 fw-bold text-secondary mb-2"><?= $code ?></div>
        <h1 class="h3 mb-3"><?= htmlspecialchars($title) ?></h1>
        <p class="text-secondary mb-4"><?= htmlspecialchars($message) ?></p>
        <div class="d-flex gap-2 justify-content-center">
            <a href="/" class="btn btn-outline-light">Startseite</a>
            <a href="javascript:history.back()" class="btn btn-outline-secondary">Zurück</a>
            <?php if (\Esse\Auth::meetsRole('author')): ?>
            <a href="/admin" class="btn btn-outline-secondary">Admin</a>
            <?php endif ?>
        </div>
    </div>
</main>

<script src="/public/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
