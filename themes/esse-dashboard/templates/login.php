<?php
/**
 * @var string               $siteName
 * @var array                $footMenu
 * @var \EsseDashboard\Theme  $theme
 */
$redirect = $_SERVER['REQUEST_URI'] ?? '/';
?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/public/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $theme->assetUrl('css/esse-dashboard.css') ?>">
    <style>
        body { display:flex; flex-direction:column; min-height:100vh; justify-content:center; align-items:center; }
        .login-box { width:100%; max-width:380px; padding:1rem; }
        .login-card { background:var(--card-bg); border:1px solid var(--border); border-radius:.75rem; padding:2rem; }
    </style>
</head>
<body>

<div class="login-box">
    <div class="text-center mb-4">
        <h1 class="h4 fw-bold text-white mb-0"><?= htmlspecialchars($siteName) ?></h1>
    </div>

    <?php if (!empty($_GET['login_error'])): ?>
    <div class="alert alert-danger py-2 small">E-Mail oder Passwort falsch.</div>
    <?php endif ?>

    <div class="login-card">
        <form method="post" action="/admin/login">
            <input type="hidden" name="_csrf"    value="<?= \Esse\Auth::csrfToken() ?>">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
            <div class="mb-3">
                <label class="form-label text-secondary small">E-Mail</label>
                <input type="email" name="login" class="form-control"
                       autocomplete="email" autofocus required
                       style="background:#0d0f14;border-color:var(--border);color:var(--text)">
            </div>
            <div class="mb-4">
                <label class="form-label text-secondary small">Passwort</label>
                <input type="password" name="password" class="form-control"
                       autocomplete="current-password" required
                       style="background:#0d0f14;border-color:var(--border);color:var(--text)">
            </div>
            <button class="btn btn-primary w-100">Anmelden</button>
        </form>
        <div class="text-center mt-3">
            <a href="/admin/forgot-password" class="text-secondary small">Passwort vergessen?</a>
        </div>
    </div>
</div>

<!-- Minimal footer — only footer menu links (e.g. Impressum) -->
<?php if ($footMenu): ?>
<footer class="position-fixed bottom-0 w-100 py-3 text-center" style="border-top:1px solid var(--border)">
    <?php foreach ($footMenu as $item): ?>
    <?php if ($item['type'] !== 'header'): ?>
    <a href="<?= htmlspecialchars(\Esse\Menu::itemUrl($item)) ?>"
       class="text-secondary small text-decoration-none mx-2"
       <?= $item['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
        <?= htmlspecialchars($item['label']) ?>
    </a>
    <?php endif ?>
    <?php endforeach ?>
</footer>
<?php endif ?>

<script src="/public/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
