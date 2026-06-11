<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;
use Esse\DB;
use Esse\Hooks;
use Esse\Menu;

// Sanitize redirect — only allow same-site paths
function sanitizeRedirect(string $url): string
{
    // Only allow paths starting with / but not //  (protocol-relative)
    if (!str_starts_with($url, '/') || str_starts_with($url, '//')) return '/';
    return $url;
}

function configuredLoginTarget(): string
{
    if (!defined('ESSE_DB_NAME')) return '/';

    $ts = DB::table('settings');
    $target = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'login_homepage_slug'") ?: '/';
    return \Esse\PageTargets::redirectUrl((string) $target, '/');
}

// Already logged in → go to intended destination or homepage
if (Auth::check()) {
    $redirect = trim($_GET['redirect'] ?? '');
    header('Location: ' . ($redirect !== '' ? sanitizeRedirect($redirect) : configuredLoginTarget()));
    exit;
}

$error = '';
$now = time();
$_SESSION['login_failures'] ??= 0;
$_SESSION['login_block_until'] ??= 0;

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
    } elseif ($_SESSION['login_block_until'] > $now) {
        $error = 'Zu viele Fehlversuche. Bitte kurz warten und erneut versuchen.';
    } else {
        $login    = trim($_POST['login']    ?? '');
        $password = $_POST['password'] ?? '';

        if (Auth::attempt($login, $password)) {
            unset($_SESSION['login_failures'], $_SESSION['login_block_until']);
            AuditLog::record('login_success', Auth::id(), Auth::user()['email'] ?? $login);
            $redirect = trim($_POST['redirect'] ?? $_GET['redirect'] ?? '');
            header('Location: ' . ($redirect !== '' ? sanitizeRedirect($redirect) : configuredLoginTarget()));
            exit;
        }

        // Passwort korrekt, aber TOTP als zweiter Faktor erforderlich — Auth::attempt()
        // hat das in der Session vermerkt. Kein Fehlversuch, sondern Weiterleitung zur Prüfung.
        if (!empty($_SESSION['esse_2fa_uid'])) {
            unset($_SESSION['login_failures'], $_SESSION['login_block_until']);
            $redirect = trim($_POST['redirect'] ?? $_GET['redirect'] ?? '');
            $target   = '/admin/verify-2fa';
            if ($redirect !== '') $target .= '?redirect=' . rawurlencode(sanitizeRedirect($redirect));
            header('Location: ' . $target);
            exit;
        }

        $_SESSION['login_failures']++;
        if ($_SESSION['login_failures'] >= 5) {
            $_SESSION['login_block_until'] = $now + 60;
            $_SESSION['login_failures'] = 0;
            AuditLog::record('login_locked', null, $login);
        } else {
            AuditLog::record('login_failed', null, $login);
        }
        // If the login came from the navbar dropdown (not the admin login page), go back with error param
        $redirect   = sanitizeRedirect($_POST['redirect'] ?? '/');
        $fromAdmin  = ($_POST['_form'] ?? '') === 'admin_login'
                      || str_starts_with($redirect, '/admin');
        if (!$fromAdmin) {
            header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'login_error=1#navbar-login-form');
            exit;
        }
        $error = 'E-Mail oder Passwort falsch.';
    }
}

$brandName   = 'ESSE CMS';
$brandSlogan = '';
if (defined('ESSE_DB_NAME')) {
    $ts          = DB::table('settings');
    $brandRows   = array_column(
        DB::fetchAll("SELECT `key`, `value` FROM `{$ts}` WHERE `key` IN ('site_name', 'site_slogan')"),
        'value', 'key'
    );
    $brandName   = $brandRows['site_name']   ?? $brandName;
    $brandSlogan = $brandRows['site_slogan'] ?? '';
}

// Themes dürfen die Gestaltung von /login übernehmen — /admin/login bleibt IMMER beim
// Standard-Rendering (Fail-Safe-Notausgang, falls ein Theme defekt ist oder deaktiviert wird).
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (!str_starts_with($requestPath, '/admin') && Hooks::has('auth.login.render')) {
    $registrationEnabled = false;
    if (defined('ESSE_DB_NAME')) {
        $ts = DB::table('settings');
        $registrationEnabled = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'registration_enabled'") === '1';
    }

    Hooks::fire('auth.login.render', [
        'error'               => $error,
        'redirect'            => trim($_GET['redirect'] ?? $_POST['redirect'] ?? ''),
        'csrfToken'           => Auth::csrfToken(),
        'brandName'           => $brandName,
        'brandSlogan'         => $brandSlogan,
        'footMenu'            => $footMenu,
        'registrationEnabled' => $registrationEnabled,
    ]);
    return;
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
    <link rel="stylesheet" href="/public/assets/css/auth.css">
</head>
<body class="d-flex align-items-center justify-content-center vh-100">
<div class="auth-box">
    <div class="text-center mb-4">
        <div class="brand text-white"><?= htmlspecialchars($brandName) ?></div>
        <?php if ($brandSlogan !== ''): ?>
        <small class="text-secondary"><?= htmlspecialchars($brandSlogan) ?></small>
        <?php endif ?>
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
            <form method="post" action="/login">
                <input type="hidden" name="_csrf"    value="<?= Auth::csrfToken() ?>">
                <input type="hidden" name="_form"    value="admin_login">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['redirect'] ?? '') ?>">
                <div class="mb-3">
                    <label class="form-label">E-Mail</label>
                    <input type="email" name="login" class="form-control" autocomplete="username" autofocus required>
                </div>
                <div class="mb-4">
                    <label class="form-label">Passwort</label>
                    <input type="password" name="password" class="form-control" autocomplete="current-password" required>
                </div>
                <button class="btn btn-primary w-100">Anmelden</button>
            </form>

            <div class="d-none mt-3" id="passkey-login-block">
                <div class="d-flex align-items-center my-3">
                    <hr class="border-secondary flex-grow-1 my-0">
                    <span class="text-secondary small mx-2">oder</span>
                    <hr class="border-secondary flex-grow-1 my-0">
                </div>
                <button type="button" id="passkey-login-btn" class="btn btn-outline-light w-100">
                    <i class="bi bi-fingerprint me-1"></i>Mit Passkey anmelden
                </button>
                <div class="text-danger small mt-2 d-none" id="passkey-login-error"></div>
            </div>
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
<footer class="position-fixed bottom-0 w-100 py-3 auth-footer">
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
    <script type="application/json" id="passkey-login-config"><?= json_encode([
        'csrf' => Auth::csrfToken(),
        'redirect' => $_GET['redirect'] ?? '',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <script src="/public/assets/js/webauthn.js"></script>
    <script src="/public/assets/js/passkey-login.js"></script>
</body>
</html>
