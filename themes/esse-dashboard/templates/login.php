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
        body { display:flex; flex-direction:column; min-height:100vh; }
        .login-wrap { flex:1; display:flex; justify-content:center; align-items:center; padding:2rem 1rem; }
        .login-box { width:100%; max-width:380px; padding:1rem; }
        .login-card { background:var(--card-bg); border:1px solid var(--border); border-radius:.75rem; padding:2rem; }
    </style>
</head>
<body>

<div class="login-wrap"><div class="login-box">
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
                       autocomplete="username" autofocus required
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
</div></div>

<!-- Footer: grouped by headers, same pattern as esse-base -->
<?php if ($footMenu):
    // Build groups: each header starts a new group, its children are the links
    $groups  = [];
    $current = ['header' => null, 'links' => []];
    foreach ($footMenu as $item) {
        if ($item['type'] === 'header') {
            if ($current['header'] !== null || !empty($current['links'])) {
                $groups[] = $current;
            }
            $current = ['header' => $item['label'], 'links' => $item['children'] ?? []];
        } else {
            $current['links'][] = $item;
        }
    }
    if ($current['header'] !== null || !empty($current['links'])) {
        $groups[] = $current;
    }
?>
<footer class="w-100 py-4 mt-auto" style="border-top:1px solid var(--border)">
    <div class="d-flex flex-wrap justify-content-center gap-5">
        <?php foreach ($groups as $group): ?>
        <div>
            <?php if ($group['header'] !== null): ?>
            <p class="text-white small fw-semibold mb-1"><?= htmlspecialchars($group['header']) ?></p>
            <hr class="border-secondary mt-0 mb-2">
            <?php endif ?>
            <?php foreach ($group['links'] as $link): ?>
            <?php if ($link['type'] === 'header'): ?>
            <p class="text-secondary small mb-1" style="font-size:.8rem"><?= htmlspecialchars($link['label']) ?></p>
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
