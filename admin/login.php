<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;
use Esse\Menu;

// Already logged in → go to intended destination or homepage
if (Auth::check()) {
    $redirect = $_GET['redirect'] ?? '/';
    header('Location: ' . $redirect);
    exit;
}

$error = '';

// Load footer menu from active theme settings
$footMenu = [];
if (defined('ESSE_DB_NAME')) {
    $ts          = DB::table('settings');
    $activeTheme = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'active_theme'") ?? '';
    $footSlugKey = 'theme_' . $activeTheme . '_menu_footer';
    $footSlug    = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = ?", [$footSlugKey]) ?? '';
    if ($footSlug) $footMenu = Menu::get($footSlug);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) {
        $error = 'Ungültige Anfrage. Bitte die Seite neu laden.';
    } else {
        $login    = trim($_POST['login']    ?? '');
        $password = $_POST['password'] ?? '';

        if (Auth::attempt($login, $password)) {
            $redirect = $_POST['redirect'] ?? $_GET['redirect'] ?? '/';
            // Sanitize redirect — only allow same-site paths
            if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
                $redirect = '/';
            }
            header('Location: ' . $redirect);
            exit;
        }
        // If the login came from the navbar dropdown, go back with error param
        $redirect = $_POST['redirect'] ?? '/';
        if ($redirect !== '/admin/login' && !str_starts_with($redirect, '/admin')) {
            header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'login_error=1#navbar-login-form');
            exit;
        }
        $error = 'E-Mail oder Passwort falsch.';
    }
}

?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — ESSE CMS</title>
    <link rel="stylesheet" href="/public/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/public/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <style>
        body { background: #0d0d0d; color: #e0e0e0; }
        .card { background: #1a1a1a; border: 1px solid #2d2d2d; }
        .form-control { background: #111; border-color: #333; color: #e0e0e0; }
        .form-control:focus { background: #111; border-color: #555; color: #e0e0e0; box-shadow: none; }
        .brand { font-size: 1.4rem; font-weight: 700; letter-spacing: .1em; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center vh-100">
<div style="width:100%;max-width:380px;padding:1rem">
    <div class="text-center mb-4">
        <div class="brand text-white">ESSE CMS</div>
        <small class="text-secondary">forge your web.</small>
    </div>
    <?php
    // Show "back to website" only if there is a public homepage (not dashboard theme)
    $ts          = defined('ESSE_DB_NAME') ? \Esse\DB::table('settings') : null;
    $activeTheme = $ts ? (\Esse\DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'active_theme'") ?? '') : '';
    $homepage    = $ts ? (\Esse\DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'homepage_slug'") ?? '') : '';
    if ($homepage && $activeTheme !== 'esse-dashboard'):
    ?>
    <div class="text-center mb-3">
        <a href="/" class="text-secondary small">
            <i class="bi bi-arrow-left me-1"></i>Zurück zur Website
        </a>
    </div>
    <?php endif ?>

    <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif ?>

    <div class="card">
        <div class="card-body p-4">
            <form method="post" action="/admin/login">
                <input type="hidden" name="_csrf"    value="<?= Auth::csrfToken() ?>">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['redirect'] ?? '/') ?>">
                <div class="mb-3">
                    <label class="form-label">E-Mail</label>
                    <input type="email" name="login" class="form-control" autocomplete="email" autofocus required>
                </div>
                <div class="mb-4">
                    <label class="form-label">Passwort</label>
                    <input type="password" name="password" class="form-control" autocomplete="current-password" required>
                </div>
                <button class="btn btn-primary w-100">Anmelden</button>
            </form>
        </div>
    </div>
    <div class="text-center mt-3 d-flex justify-content-center gap-3">
        <a href="/admin/forgot-password" class="text-secondary small">Passwort vergessen?</a>
        <?php
        // Show register link only if registration is enabled
        if (defined('ESSE_DB_NAME')) {
            $ts  = \Esse\DB::table('settings');
            $reg = \Esse\DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'registration_enabled'");
            if ($reg === '1') {
                echo '<a href="/registrieren" class="text-secondary small">Registrieren</a>';
            }
        }
        ?>
    </div>
</div>

<?php if ($footMenu):
    // Group by headers (same pattern as esse-base/esse-dashboard footer)
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
<footer class="position-fixed bottom-0 w-100 py-3" style="background:#0d0d0d;border-top:1px solid #1e1e1e">
    <div class="d-flex flex-wrap justify-content-center gap-5">
        <?php foreach ($groups as $group): ?>
        <div class="text-center">
            <?php if ($group['header'] !== null): ?>
            <p class="text-white small fw-semibold mb-1"><?= htmlspecialchars($group['header']) ?></p>
            <hr class="border-secondary mt-0 mb-1">
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
</body>
</html>
