<?php
/**
 * @var array            $page
 * @var string           $siteName
 * @var \EsseCyber\Theme  $theme
 */
$code    = (int) ($page['error_code'] ?? 404);
$title   = $page['error_title']   ?? 'ERROR';
$message = $page['error_message'] ?? '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $code ?> // <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="<?= $theme->assetUrl('css/esse-cyber.css') ?>">
</head>
<body>
<div class="cyber-grid"></div>
<div class="cyber-glow"></div>

<main class="cyber-main" style="align-items:center">
    <div class="cyber-error">
        <div class="cyber-error-code"><?= $code ?></div>
        <div class="cyber-error-title">// <?= htmlspecialchars(strtoupper($title)) ?></div>
        <div class="cyber-error-msg"><?= htmlspecialchars($message) ?></div>
        <div class="cyber-error-links">
            <a href="/" class="cyber-btn">// HOME</a>
            <a href="javascript:history.back()" class="cyber-btn">// BACK</a>
        </div>
    </div>
</main>
</body>
</html>
