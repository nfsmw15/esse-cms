<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;

$tm    = DB::table('menus');
$ti    = DB::table('menu_items');
$menus = DB::fetchAll(
    "SELECT m.*, COUNT(i.id) AS item_count
       FROM `{$tm}` m
  LEFT JOIN `{$ti}` i ON i.menu_id = m.id AND i.parent_id IS NULL
   GROUP BY m.id
   ORDER BY m.name ASC"
);

$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// Handle create new menu (POST on this page)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'create_menu') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $name = trim($_POST['name'] ?? '');
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', trim($_POST['slug'] ?? $name)));
    $slug = trim($slug, '-');

    if ($name && $slug) {
        $existing = DB::fetch("SELECT id FROM `{$tm}` WHERE slug = ?", [$slug]);
        if ($existing) {
            $flash = ['type' => 'danger', 'message' => "Slug '{$slug}' ist bereits vergeben."];
        } else {
            DB::insert($tm, ['name' => $name, 'slug' => $slug]);
            $id = DB::fetch("SELECT id FROM `{$tm}` WHERE slug = ?", [$slug])['id'];
            header("Location: /admin/menus/edit/{$id}");
            exit;
        }
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete_menu') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }
    $id = (int) ($_POST['menu_id'] ?? 0);
    if ($id) {
        DB::delete($tm, ['id' => $id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Menü gelöscht.'];
    }
    header('Location: /admin/menus');
    exit;
}

$pageTitle = 'Menüs';
$activeNav = 'menus';

ob_start();
?>
<?php if ($flash): ?>
<div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif ?>

<div class="row g-4">
    <div class="col-lg-8">
        <?php if ($menus): ?>
        <div class="card">
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr>
                        <th>Name</th>
                        <th>Slug</th>
                        <th>Einträge</th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($menus as $menu): ?>
                    <tr>
                        <td>
                            <a href="/admin/menus/edit/<?= $menu['id'] ?>"
                               class="text-white text-decoration-none fw-semibold">
                                <?= htmlspecialchars($menu['name']) ?>
                            </a>
                        </td>
                        <td><code class="text-secondary small"><?= htmlspecialchars($menu['slug']) ?></code></td>
                        <td class="text-secondary"><?= $menu['item_count'] ?></td>
                        <td class="text-end">
                            <a href="/admin/menus/edit/<?= $menu['id'] ?>"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="post" action="/admin/menus" class="d-inline"
                                  onsubmit="return confirm('Menü wirklich löschen?')">
                                <input type="hidden" name="_csrf"     value="<?= Auth::csrfToken() ?>">
                                <input type="hidden" name="_action"   value="delete_menu">
                                <input type="hidden" name="menu_id"   value="<?= $menu['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-body text-center text-secondary py-5">
                Noch keine Menüs vorhanden.
            </div>
        </div>
        <?php endif ?>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header py-2"><small class="text-secondary">Neues Menü</small></div>
            <div class="card-body">
                <form method="post" action="/admin/menus">
                    <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
                    <input type="hidden" name="_action" value="create_menu">
                    <div class="mb-2">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" id="menu-name" class="form-control"
                               placeholder="z.B. Hauptnavigation" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Slug</label>
                        <input type="text" name="slug" id="menu-slug"
                               class="form-control font-monospace"
                               placeholder="z.B. main" required>
                        <div class="form-text">Wird im Theme verwendet: <code>Menu::get('main')</code></div>
                    </div>
                    <button class="btn btn-primary w-100">Menü erstellen</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const nameEl = document.getElementById('menu-name');
const slugEl = document.getElementById('menu-slug');
let slugEdited = false;

nameEl?.addEventListener('input', () => {
    if (!slugEdited) {
        slugEl.value = nameEl.value.toLowerCase()
            .replace(/[äöü]/g, c => ({ä:'ae',ö:'oe',ü:'ue'}[c] || c))
            .replace(/ß/g, 'ss')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }
});
slugEl?.addEventListener('input', () => { slugEdited = slugEl.value.length > 0; });
</script>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
