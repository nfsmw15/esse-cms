<?php
/**
 * @var array            $page
 * @var string           $content
 * @var string           $siteName
 * @var array            $mainMenu
 * @var array            $footMenu
 * @var \EsseCyber\Theme  $theme
 */
$currentSlug = $page['slug'] ?? '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page['title'] . ' // ' . $siteName) ?></title>
    <link rel="stylesheet" href="<?= $theme->assetUrl('css/esse-cyber.css') ?>">
</head>
<body>

<div class="cyber-grid"></div>
<div class="cyber-glow"></div>
<div class="cyber-corner tl"></div>
<div class="cyber-corner tr"></div>
<div class="cyber-corner bl"></div>
<div class="cyber-corner br"></div>

<!-- Topbar -->
<nav class="cyber-topbar">
    <a href="/" class="cyber-logo"><?= htmlspecialchars(strtoupper($siteName)) ?></a>

    <div class="cyber-nav">
        <?php foreach ($mainMenu as $item):
            $url = \Esse\Menu::itemUrl($item);
            $isActive = $currentSlug === ltrim($url, '/');
        ?>
        <?php if (!empty($item['children'])): ?>
        <div class="cyber-dropdown">
            <a href="<?= htmlspecialchars($url) ?>" class="<?= $isActive ? 'active' : '' ?>">
                <?= htmlspecialchars($item['label']) ?> ▾
            </a>
            <div class="cyber-dropdown-menu">
                <?php foreach ($item['children'] as $child): ?>
                <?php if ($child['type'] !== 'header'): ?>
                <a href="<?= htmlspecialchars(\Esse\Menu::itemUrl($child)) ?>"
                   <?= $child['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
                    <?= htmlspecialchars($child['label']) ?>
                </a>
                <?php endif ?>
                <?php endforeach ?>
            </div>
        </div>
        <?php elseif ($item['type'] !== 'header'): ?>
        <a href="<?= htmlspecialchars($url) ?>"
           class="<?= $isActive ? 'active' : '' ?>"
           <?= $item['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
            <?= htmlspecialchars($item['label']) ?>
        </a>
        <?php endif ?>
        <?php endforeach ?>
    </div>

    <div style="display:flex;align-items:center;gap:1rem">
        <?php if (\Esse\Auth::check()): ?>
        <div class="cyber-user">
            [ <?= htmlspecialchars(\Esse\Auth::user()['display_name'] ?? '') ?> ▾ ]
            <div class="cyber-user-menu">
                <a href="/profil">// Profil</a>
                <?php if (\Esse\Auth::meetsRole('author')): ?>
                <a href="/admin">// Admin</a>
                <?php endif ?>
                <form method="post" action="/abmelden">
                    <input type="hidden" name="_csrf" value="<?= \Esse\Auth::csrfToken() ?>">
                    <button>// Abmelden</button>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="cyber-user">
            [ Login ▾ ]
            <div class="cyber-user-menu" style="min-width:220px;padding:.75rem">
                <?php if (!empty($_GET['login_error'])): ?>
                <div style="font-family:var(--mono);font-size:.65rem;color:#f87171;padding:.25rem .5rem .75rem;border-bottom:1px solid var(--border);margin-bottom:.5rem">
                    // AUTH FAILED
                </div>
                <?php endif ?>
                <form method="post" action="/admin/login" style="display:flex;flex-direction:column;gap:.5rem;padding:.25rem">
                    <input type="hidden" name="_csrf"    value="<?= \Esse\Auth::csrfToken() ?>">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/') ?>">
                    <input type="email" name="login" placeholder="E-MAIL"
                           autocomplete="username" required
                           style="background:var(--bg);border:1px solid var(--border);color:var(--text);font-family:var(--mono);font-size:.65rem;padding:.4rem .6rem;letter-spacing:.08em;width:100%">
                    <input type="password" name="password" placeholder="PASSWORD"
                           autocomplete="current-password" required
                           style="background:var(--bg);border:1px solid var(--border);color:var(--text);font-family:var(--mono);font-size:.65rem;padding:.4rem .6rem;letter-spacing:.08em;width:100%">
                    <button type="submit" class="cyber-btn" style="text-align:center;cursor:pointer;background:none">
                        // LOGIN
                    </button>
                </form>
                <a href="/admin/forgot-password" style="display:block;text-align:center;padding:.25rem;font-family:var(--mono);font-size:.6rem;color:var(--muted);text-decoration:none">
                    forgot password
                </a>
            </div>
        </div>
        <?php endif ?>

        <div class="cyber-status">
            <div class="cyber-status-dot"></div>
            <span>ONLINE</span>
        </div>
    </div>
</nav>

<!-- Content -->
<main class="cyber-main">
    <div style="width:100%;max-width:860px">
        <?php if (!empty($page['title'])): ?>
        <h1 class="cyber-page-title">
            <?php if (!empty($page['icon'])): ?><i class="<?= htmlspecialchars($page['icon']) ?>"></i><?php endif ?>
            <?= htmlspecialchars($page['title']) ?>
        </h1>
        <?php endif ?>
        <div class="cyber-content-wrap">
            <div class="cyber-prose">
                <?= $content ?>
            </div>
        </div>
    </div>
</main>

<!-- Footer -->
<footer class="cyber-footer">
    <div class="cyber-clock" id="cyber-clock">--:--:--</div>
    <?php if ($footMenu):
        // Group by headers
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
    <div style="display:flex;gap:2.5rem;align-items:flex-start">
        <?php foreach ($groups as $group): ?>
        <div>
            <?php if ($group['header'] !== null): ?>
            <div style="font-family:var(--mono);font-size:.6rem;color:var(--accent);letter-spacing:.12em;padding-bottom:.35rem">
                <?= htmlspecialchars(strtoupper($group['header'])) ?>
            </div>
            <div style="height:1px;background:rgba(232,100,10,0.35);margin-bottom:.6rem"></div>
            <?php endif ?>
            <?php foreach ($group['links'] as $link): ?>
            <?php if ($link['type'] === 'header'): ?>
            <div style="font-family:var(--mono);font-size:.6rem;color:var(--muted);letter-spacing:.08em;margin-bottom:.2rem">
                <?= htmlspecialchars($link['label']) ?>
            </div>
            <?php else: ?>
            <div>
                <a href="<?= htmlspecialchars(\Esse\Menu::itemUrl($link)) ?>"
                   class="cyber-footer-link"
                   <?= $link['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
                    <?= htmlspecialchars($link['label']) ?>
                </a>
            </div>
            <?php endif ?>
            <?php endforeach ?>
        </div>
        <?php endforeach ?>
    </div>
    <?php endif ?>
</footer>

<script>
(function() {
    function tick() {
        const n = new Date(), p = n => String(n).padStart(2,'0');
        document.getElementById('cyber-clock').textContent =
            p(n.getHours())+':'+p(n.getMinutes())+':'+p(n.getSeconds());
    }
    tick();
    setInterval(tick, 1000);
})();
</script>
</body>
</html>
