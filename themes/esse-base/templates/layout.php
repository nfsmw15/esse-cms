<?php
/**
 * @var array          $page
 * @var string         $content
 * @var string         $siteName
 * @var array          $mainMenu
 * @var array          $footMenu
 * @var array          $settings
 * @var \EsseBase\Theme $theme
 */
$loginFailed = !empty($_GET['login_error']);
$metaDescription = ($page['meta_description'] ?? '') ?: ($settings['seo_meta_description'] ?? '');
?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page['title'] . ' — ' . $siteName) ?></title>
    <?php if ($metaDescription !== ''): ?>
    <meta name="description" content="<?= htmlspecialchars($metaDescription) ?>">
    <?php endif ?>
    <meta property="og:title" content="<?= htmlspecialchars($page['title'] . ' — ' . $siteName) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= htmlspecialchars('https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/')) ?>">
    <?php if ($metaDescription !== ''): ?>
    <meta property="og:description" content="<?= htmlspecialchars($metaDescription) ?>">
    <?php endif ?>
    <link rel="stylesheet" href="/public/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/public/vendor/esse-ui/esse-ui.css">
    <?= \Esse\Ui::iconPackCssTag() ?>
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
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <?= htmlspecialchars($item['label']) ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <?php if ($item['type'] !== 'header' && $url !== '#'): ?>
                        <li>
                            <a class="dropdown-item"
                               href="<?= htmlspecialchars($url) ?>"
                               <?= $item['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
                                <i class="bi bi-arrow-right-circle me-1 text-secondary"></i>
                                <?= htmlspecialchars($item['label']) ?>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif ?>
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
                        <?php if (!empty($item['icon'])): ?><i class="<?= htmlspecialchars($item['icon']) ?> me-1"></i><?php endif ?>
                        <?= htmlspecialchars($item['label']) ?>
                    </a>
                </li>
                <?php endif ?>
                <?php endforeach ?>
            </ul>

            <!-- User menu (right side of navbar) -->
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                <?php if (\Esse\Auth::check()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-1"></i>
                        <?= htmlspecialchars(\Esse\Auth::user()['display_name'] ?? '') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
                        <li>
                            <a class="dropdown-item" href="/profil">
                                <i class="bi bi-person me-2"></i>Mein Profil
                            </a>
                        </li>
                        <?php if (\Esse\Auth::meetsRole('author')): ?>
                        <li>
                            <a class="dropdown-item" href="/admin">
                                <i class="bi bi-speedometer2 me-2"></i>Admin
                            </a>
                        </li>
                        <?php endif ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="/abmelden" class="d-inline w-100">
                                <input type="hidden" name="_csrf" value="<?= \Esse\Auth::csrfToken() ?>">
                                <button class="dropdown-item text-danger">
                                    <i class="bi bi-box-arrow-right me-2"></i>Abmelden
                                </button>
                            </form>
                        </li>
                    </ul>
                </li>
                <?php else: ?>
                <li class="nav-item dropdown">
                    <a id="navbar-login-toggle"
                       class="nav-link dropdown-toggle<?= $loginFailed ? ' show' : '' ?>"
                       href="#"
                       data-bs-toggle="dropdown"
                       data-bs-auto-close="outside"
                       aria-expanded="<?= $loginFailed ? 'true' : 'false' ?>">
                        <i class="bi bi-person me-1"></i>Anmelden
                    </a>
                    <div class="dropdown-menu dropdown-menu-dark dropdown-menu-end p-3 esse-login-menu<?= $loginFailed ? ' show' : '' ?>"
                         <?= $loginFailed ? 'data-bs-popper="static"' : '' ?>>
                        <?php if ($loginFailed): ?>
                        <div class="alert alert-danger py-1 px-2 small mb-2">
                            E-Mail oder Passwort falsch.
                        </div>
                        <?php endif ?>
                        <form method="post" action="/login" id="navbar-login-form">
                            <input type="hidden" name="_csrf"    value="<?= \Esse\Auth::csrfToken() ?>">
                            <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/') ?>">
                            <div class="mb-2">
                                <input type="email" name="login" class="form-control form-control-sm"
                                       placeholder="E-Mail" autocomplete="username" required>
                            </div>
                            <div class="mb-3">
                                <input type="password" name="password" class="form-control form-control-sm"
                                       placeholder="Passwort" autocomplete="current-password" required>
                            </div>
                            <button class="btn btn-primary btn-sm w-100">Anmelden</button>
                        </form>
                        <?php
                        $ts  = \Esse\DB::table('settings');
                        $reg = \Esse\DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'registration_enabled'");
                        ?>
                        <div class="mt-2 text-center">
                            <a href="/passwort-vergessen" class="text-secondary small">Passwort vergessen?</a>
                            <?php if ($reg === '1'): ?>
                            · <a href="/registrieren" class="text-secondary small">Registrieren</a>
                            <?php endif ?>
                        </div>
                        <?php if (($page['slug'] ?? '') !== 'login'): ?>
                        <div class="d-none mt-3" id="passkey-login-block">
                            <div class="d-flex align-items-center my-2">
                                <hr class="border-secondary flex-grow-1 my-0">
                                <span class="text-secondary small mx-2">oder</span>
                                <hr class="border-secondary flex-grow-1 my-0">
                            </div>
                            <button type="button" id="passkey-login-btn" class="btn btn-outline-light btn-sm w-100">
                                <i class="bi bi-fingerprint me-1"></i>Mit Passkey anmelden
                            </button>
                            <div class="text-danger small mt-2 d-none" id="passkey-login-error"></div>
                        </div>
                        <?php endif ?>
                    </div>
                </li>
                <?php endif ?>
            </ul>
        </div>
    </div>
</nav>

<!-- Content -->
<main class="container py-5">
    <?php if (empty($page['hide_title']) && (!empty($page['icon']) || $page['title'])): ?>
    <h1 class="mb-4">
        <?php if (!empty($page['icon'])): ?>
        <?php
        $pi = $page['icon'];
        echo str_contains($pi, ' ')
            ? '<i class="' . htmlspecialchars($pi) . ' me-2"></i> '
            : \Esse\Ui::icon(preg_replace('/^(bi|ph|ti|lucide|ri)-/', '', $pi)) . ' ';
        ?>
        <?php endif ?>
        <?= htmlspecialchars($page['title']) ?>
    </h1>
    <?php endif ?>
    <div class="esse-content">
        <?= $content ?>
    </div>
</main>

<!-- Footer -->
<?php if ($footMenu || !empty($siteName)): ?>
<footer class="border-top border-secondary mt-5 pt-4 pb-3">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-4">
            <!-- Copyright -->
            <div class="align-self-end">
                <span class="text-secondary small">&copy; <?= date('Y') ?> <?= htmlspecialchars($siteName) ?></span>
            </div>

            <?php if ($footMenu): ?>
            <!-- Footer menu groups — always side by side -->
            <?php
            $groups = [];
            $current = ['header' => null, 'links' => []];
            foreach ($footMenu as $item) {
                if ($item['type'] === 'header') {
                    if ($current['header'] !== null || !empty($current['links'])) {
                        $groups[] = $current;
                    }
                    // Children of the header count as its links
                    $current = [
                        'header' => $item['label'],
                        'links'  => $item['children'] ?? [],
                    ];
                } else {
                    // Top-level non-header items → add to current group's links
                    $current['links'][] = $item;
                }
            }
            if ($current['header'] !== null || !empty($current['links'])) {
                $groups[] = $current;
            }
            ?>
            <div class="d-flex flex-wrap gap-5">
            <?php foreach ($groups as $group): ?>
                <div>
                    <?php if ($group['header'] !== null): ?>
                    <p class="text-white small fw-semibold mb-1"><?= htmlspecialchars($group['header']) ?></p>
                    <hr class="border-secondary mt-0 mb-2">
                    <?php endif ?>
                    <?php foreach ($group['links'] as $link): ?>
                    <?php if ($link['type'] === 'header'): ?>
                    <p class="text-secondary small mb-1 mt-2">
                        <?= htmlspecialchars($link['label']) ?>
                    </p>
                    <?php else: ?>
                    <div>
                        <a href="<?= htmlspecialchars(\Esse\Menu::itemUrl($link)) ?>"
                           class="text-secondary text-decoration-none small"
                           <?= $link['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
                            <?php if (!empty($link['icon'])):
                                $li = $link['icon'];
                                echo str_contains($li, ' ')
                                    ? '<i class="' . htmlspecialchars($li) . ' me-1"></i>'
                                    : \Esse\Ui::icon(preg_replace('/^(bi|ph|ti|lucide|ri)-/', '', $li)) . ' ';
                            endif; ?>
                            <?= htmlspecialchars($link['label']) ?>
                        </a>
                    </div>
                    <?php endif ?>
                    <?php endforeach ?>
                </div>
            <?php endforeach ?>
            </div>
            <?php endif ?>
        </div>
    </div>
</footer>
<?php endif ?>

<script src="/public/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script type="application/json" id="frontend-login-config"><?= json_encode(['loginFailed' => $loginFailed], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/public/assets/js/frontend-login-dropdown.js"></script>
<?php if (!\Esse\Auth::check() && ($page['slug'] ?? '') !== 'login'): ?>
<script type="application/json" id="passkey-login-config"><?= json_encode([
    'csrf'     => \Esse\Auth::csrfToken(),
    'redirect' => $_SERVER['REQUEST_URI'] ?? '/',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/public/assets/js/webauthn.js"></script>
<script src="/public/assets/js/passkey-login.js"></script>
<?php endif ?>
</body>
</html>
