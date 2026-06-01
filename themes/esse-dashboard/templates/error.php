<?php
/**
 * @var array                $page
 * @var string               $content
 * @var string               $siteName
 * @var array                $sidebarMenu
 * @var \EsseDashboard\Theme  $theme
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
    <title><?= $code ?> — <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/public/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $theme->assetUrl('css/esse-dashboard.css') ?>">
</head>
<body class="d-flex align-items-center justify-content-center vh-100">
<div class="text-center">
    <div class="display-1 fw-bold" style="color:#2a2d35"><?= $code ?></div>
    <h1 class="h3 mb-2"><?= htmlspecialchars($title) ?></h1>
    <p class="text-secondary mb-4"><?= htmlspecialchars($message) ?></p>
    <div class="d-flex gap-2 justify-content-center">
        <a href="/" class="btn btn-outline-light btn-sm">Startseite</a>
        <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">Zurück</a>
    </div>
</div>
<script src="/public/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
