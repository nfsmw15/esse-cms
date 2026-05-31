<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;

$t  = DB::table('pages');
$tu = DB::table('users');

// Filter
$filter = $_GET['status'] ?? 'all';
$where  = $filter !== 'all' ? "WHERE p.status = " . DB::query("SELECT ?", [$filter])->fetchColumn() : '';

$sql = "SELECT p.*, u.display_name
          FROM `{$t}` p
     LEFT JOIN `{$tu}` u ON u.id = p.author_id
      ORDER BY p.updated_at DESC";

$pages = DB::fetchAll($sql);

// Flash message
$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

$pageTitle = 'Seiten';
$activeNav = 'pages';

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex gap-2">
        <?php foreach (['all' => 'Alle', 'published' => 'Live', 'draft' => 'Entwürfe'] as $val => $label): ?>
        <a href="/admin/pages?status=<?= $val ?>"
           class="btn btn-sm <?= $filter === $val ? 'btn-primary' : 'btn-outline-secondary' ?>">
            <?= $label ?>
        </a>
        <?php endforeach ?>
    </div>
    <a href="/admin/pages/create" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> Neue Seite
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php
        // Apply status filter in PHP to keep SQL simple
        $filtered = $filter === 'all'
            ? $pages
            : array_values(array_filter($pages, fn($p) => $p['status'] === $filter));
        ?>
        <?php if ($filtered): ?>
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Titel</th>
                    <th>Slug</th>
                    <th>Typ</th>
                    <th>Sichtbarkeit</th>
                    <th>Status</th>
                    <th>Autor</th>
                    <th>Geändert</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($filtered as $page): ?>
            <tr>
                <td>
                    <a href="/admin/pages/edit/<?= htmlspecialchars($page['slug']) ?>"
                       class="text-white text-decoration-none fw-semibold">
                        <?= htmlspecialchars($page['title']) ?>
                    </a>
                </td>
                <td><code class="text-secondary small">/<?= htmlspecialchars($page['slug']) ?></code></td>
                <td>
                    <?php if ($page['type'] === 'php'): ?>
                        <span class="badge bg-warning text-dark">PHP</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Standard</span>
                    <?php endif ?>
                </td>
                <td>
                    <?php
                    $visLabels = ['public' => ['success', 'Öffentlich'], 'members' => ['info', 'Mitglieder'], 'admin' => ['danger', 'Admin']];
                    [$vc, $vl] = $visLabels[$page['visibility']] ?? ['secondary', $page['visibility']];
                    ?>
                    <span class="badge bg-<?= $vc ?>"><?= $vl ?></span>
                </td>
                <td>
                    <?php if ($page['status'] === 'published'): ?>
                        <span class="badge bg-success">Live</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Entwurf</span>
                    <?php endif ?>
                </td>
                <td class="text-secondary small"><?= htmlspecialchars($page['display_name'] ?? '—') ?></td>
                <td class="text-secondary small"><?= date('d.m.Y H:i', strtotime($page['updated_at'])) ?></td>
                <td class="text-end">
                    <a href="/admin/pages/edit/<?= htmlspecialchars($page['slug']) ?>"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <form method="post" action="/admin/pages/delete/<?= htmlspecialchars($page['slug']) ?>"
                          class="d-inline"
                          onsubmit="return confirm('Seite \'<?= htmlspecialchars(addslashes($page['title'])) ?>\' wirklich löschen?')">
                        <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                        <button class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-4 text-center text-secondary">
            Keine Seiten gefunden.
            <a href="/admin/pages/create" class="text-primary">Erste Seite erstellen</a>
        </div>
        <?php endif ?>
    </div>
</div>

<?php
// Plugin-registered pages
$pluginPages = \Esse\Plugin::getRegisteredPages();
if ($pluginPages):
?>
<div class="card mt-4">
    <div class="card-header py-2 d-flex align-items-center gap-2">
        <i class="bi bi-puzzle text-secondary"></i>
        <strong>Plugin-Seiten</strong>
        <small class="text-secondary ms-1">— registriert durch Plugins, nicht editierbar</small>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr>
                <th>Titel</th>
                <th>URL</th>
                <th>Plugin</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($pluginPages as $pp): ?>
            <tr class="align-middle">
                <td>
                    <i class="bi <?= htmlspecialchars($pp['icon']) ?> text-secondary me-1"></i>
                    <?= htmlspecialchars($pp['title']) ?>
                </td>
                <td><code class="text-secondary small">/<?= htmlspecialchars($pp['slug']) ?></code></td>
                <td><span class="badge bg-secondary"><?= htmlspecialchars($pp['plugin_name']) ?></span></td>
                <td class="text-end">
                    <a href="/<?= htmlspecialchars($pp['slug']) ?>" target="_blank"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif ?>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
