<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;

$menuId ??= 0;
$tm = DB::table('menus');
$ti = DB::table('menu_items');
$tp = DB::table('pages');

$menu = DB::fetch("SELECT * FROM `{$tm}` WHERE id = ?", [$menuId]);
if (!$menu) { http_response_code(404); echo '404'; exit; }

$errors = [];

// -- POST actions --
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $action = $_POST['_action'] ?? '';

    switch ($action) {

        case 'save_menu':
            $name = trim($_POST['name'] ?? '');
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', trim($_POST['slug'] ?? '')));
            $slug = trim($slug, '-');
            if ($name && $slug) {
                $dup = DB::fetch("SELECT id FROM `{$tm}` WHERE slug = ? AND id != ?", [$slug, $menuId]);
                if ($dup) {
                    $errors[] = "Slug '{$slug}' ist bereits vergeben.";
                } else {
                    DB::update($tm, ['name' => $name, 'slug' => $slug], ['id' => $menuId]);
                    $menu = DB::fetch("SELECT * FROM `{$tm}` WHERE id = ?", [$menuId]);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Menü gespeichert.'];
                    header("Location: /admin/menus/edit/{$menuId}");
                    exit;
                }
            }
            break;

        case 'add_item':
            $type      = $_POST['type']      ?? 'page';
            $label     = trim($_POST['label'] ?? '');
            $pageSlug  = trim($_POST['page_slug'] ?? '');
            $url       = trim($_POST['url']   ?? '');
            $target    = $_POST['target']     ?? '_self';
            $parentId  = (int) ($_POST['parent_id'] ?? 0) ?: null;

            if (!in_array($type,   ['page','url','header'], true)) $type   = 'page';
            if (!in_array($target, ['_self','_blank'],       true)) $target = '_self';

            if (!$label) {
                $errors[] = 'Label ist Pflichtfeld.';
                break;
            }

            $maxOrder = DB::value(
                "SELECT COALESCE(MAX(sort_order),0) FROM `{$ti}` WHERE menu_id = ? AND parent_id " . ($parentId ? "= ?" : "IS NULL"),
                $parentId ? [$menuId, $parentId] : [$menuId]
            );

            DB::insert($ti, [
                'menu_id'    => $menuId,
                'parent_id'  => $parentId,
                'type'       => $type,
                'label'      => $label,
                'page_slug'  => $type === 'page' ? $pageSlug : null,
                'url'        => $type === 'url'  ? $url      : null,
                'target'     => $target,
                'sort_order' => (int)$maxOrder + 10,
            ]);
            header("Location: /admin/menus/edit/{$menuId}");
            exit;

        case 'delete_item':
            $itemId = (int) ($_POST['item_id'] ?? 0);
            if ($itemId) DB::delete($ti, ['id' => $itemId]);
            header("Location: /admin/menus/edit/{$menuId}");
            exit;

        case 'move_up':
        case 'move_down':
            $itemId = (int) ($_POST['item_id'] ?? 0);
            if ($itemId) self_moveItem($ti, $itemId, $menuId, $action === 'move_up');
            header("Location: /admin/menus/edit/{$menuId}");
            exit;
    }
}

function self_moveItem(string $ti, int $itemId, int $menuId, bool $up): void
{
    $item = \Esse\DB::fetch("SELECT * FROM `{$ti}` WHERE id = ? AND menu_id = ?", [$itemId, $menuId]);
    if (!$item) return;

    $dir    = $up ? '<' : '>';
    $order  = $up ? 'DESC' : 'ASC';
    $sibling = \Esse\DB::fetch(
        "SELECT * FROM `{$ti}` WHERE menu_id = ? AND parent_id " .
        ($item['parent_id'] ? "= ?" : "IS NULL") .
        " AND sort_order {$dir} ? ORDER BY sort_order {$order} LIMIT 1",
        $item['parent_id']
            ? [$menuId, $item['parent_id'], $item['sort_order']]
            : [$menuId, $item['sort_order']]
    );

    if ($sibling) {
        \Esse\DB::update($ti, ['sort_order' => $sibling['sort_order']], ['id' => $itemId]);
        \Esse\DB::update($ti, ['sort_order' => $item['sort_order']],    ['id' => $sibling['id']]);
    }
}

// Load items (top-level + their children)
$topItems = DB::fetchAll(
    "SELECT * FROM `{$ti}` WHERE menu_id = ? AND parent_id IS NULL ORDER BY sort_order ASC",
    [$menuId]
);
foreach ($topItems as &$top) {
    $top['children'] = DB::fetchAll(
        "SELECT * FROM `{$ti}` WHERE menu_id = ? AND parent_id = ? ORDER BY sort_order ASC",
        [$menuId, $top['id']]
    );
}
unset($top);

$pages     = DB::fetchAll("SELECT slug, title FROM `{$tp}` WHERE status = 'published' ORDER BY title ASC");
$pageTitle = 'Menü: ' . htmlspecialchars($menu['name']);
$activeNav = 'menus';

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="/admin/menus" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Alle Menüs
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach ?>
</div>
<?php endif ?>

<div class="row g-4">
    <!-- Left: items -->
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <strong>Einträge</strong>
                <small class="text-secondary">Slug: <code><?= htmlspecialchars($menu['slug']) ?></code></small>
            </div>
            <div class="card-body p-0">
                <?php if ($topItems): ?>
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php foreach ($topItems as $item): ?>
                    <tr class="align-middle">
                        <td style="width:2rem" class="ps-3 text-secondary">
                            <?php if ($item['type'] === 'header'): ?>
                                <i class="bi bi-dash-lg"></i>
                            <?php elseif ($item['target'] === '_blank'): ?>
                                <i class="bi bi-box-arrow-up-right"></i>
                            <?php else: ?>
                                <i class="bi bi-link-45deg"></i>
                            <?php endif ?>
                        </td>
                        <td>
                            <span class="fw-semibold"><?= htmlspecialchars($item['label']) ?></span>
                            <?php if ($item['type'] === 'page' && $item['page_slug']): ?>
                                <small class="text-secondary ms-1">/<?= htmlspecialchars($item['page_slug']) ?></small>
                            <?php elseif ($item['type'] === 'url' && $item['url']): ?>
                                <small class="text-secondary ms-1"><?= htmlspecialchars($item['url']) ?></small>
                            <?php endif ?>
                            <?php if (!empty($item['children'])): ?>
                            <div class="mt-1 ps-3">
                                <?php foreach ($item['children'] as $child): ?>
                                <div class="d-flex align-items-center gap-2 py-1 border-start border-secondary ps-2 ms-1">
                                    <i class="bi bi-arrow-return-right text-secondary small"></i>
                                    <span class="small"><?= htmlspecialchars($child['label']) ?></span>
                                    <?php if ($child['page_slug']): ?>
                                        <small class="text-secondary">/<?= htmlspecialchars($child['page_slug']) ?></small>
                                    <?php endif ?>
                                    <div class="ms-auto d-flex gap-1">
                                        <?php foreach (['move_up' => '↑', 'move_down' => '↓'] as $mv => $lbl): ?>
                                        <form method="post" action="/admin/menus/edit/<?= $menuId ?>" class="d-inline">
                                            <input type="hidden" name="_csrf"    value="<?= Auth::csrfToken() ?>">
                                            <input type="hidden" name="_action"  value="<?= $mv ?>">
                                            <input type="hidden" name="item_id"  value="<?= $child['id'] ?>">
                                            <button class="btn btn-sm btn-outline-secondary py-0 px-1"><?= $lbl ?></button>
                                        </form>
                                        <?php endforeach ?>
                                        <form method="post" action="/admin/menus/edit/<?= $menuId ?>" class="d-inline"
                                              onsubmit="return confirm('Eintrag löschen?')">
                                            <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
                                            <input type="hidden" name="_action" value="delete_item">
                                            <input type="hidden" name="item_id" value="<?= $child['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger py-0 px-1">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach ?>
                            </div>
                            <?php endif ?>
                        </td>
                        <td class="text-end pe-3">
                            <div class="d-flex gap-1 justify-content-end">
                                <?php foreach (['move_up' => '↑', 'move_down' => '↓'] as $mv => $lbl): ?>
                                <form method="post" action="/admin/menus/edit/<?= $menuId ?>" class="d-inline">
                                    <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
                                    <input type="hidden" name="_action" value="<?= $mv ?>">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary py-0 px-2"><?= $lbl ?></button>
                                </form>
                                <?php endforeach ?>
                                <form method="post" action="/admin/menus/edit/<?= $menuId ?>" class="d-inline"
                                      onsubmit="return confirm('Eintrag löschen?')">
                                    <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
                                    <input type="hidden" name="_action" value="delete_item">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="p-4 text-center text-secondary">Noch keine Einträge.</div>
                <?php endif ?>
            </div>
        </div>

        <!-- Rename menu -->
        <div class="card">
            <div class="card-header py-2"><small class="text-secondary">Menü umbenennen</small></div>
            <div class="card-body">
                <form method="post" action="/admin/menus/edit/<?= $menuId ?>">
                    <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
                    <input type="hidden" name="_action" value="save_menu">
                    <div class="row g-2">
                        <div class="col-6">
                            <input type="text" name="name" class="form-control"
                                   value="<?= htmlspecialchars($menu['name']) ?>" placeholder="Name" required>
                        </div>
                        <div class="col-4">
                            <input type="text" name="slug" class="form-control font-monospace"
                                   value="<?= htmlspecialchars($menu['slug']) ?>" placeholder="slug" required>
                        </div>
                        <div class="col-2">
                            <button class="btn btn-outline-secondary w-100">
                                <i class="bi bi-floppy"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right: add item -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header py-2"><small class="text-secondary">Eintrag hinzufügen</small></div>
            <div class="card-body">
                <form method="post" action="/admin/menus/edit/<?= $menuId ?>">
                    <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
                    <input type="hidden" name="_action" value="add_item">
                    <div class="mb-2">
                        <label class="form-label">Typ</label>
                        <select name="type" id="item-type" class="form-select form-select-sm">
                            <option value="page">Seite</option>
                            <option value="url">Externe URL</option>
                            <option value="header">Trenner / Überschrift</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Label</label>
                        <input type="text" name="label" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2" id="field-page">
                        <label class="form-label">Seite</label>
                        <select name="page_slug" class="form-select form-select-sm">
                            <option value="">— wählen —</option>
                            <?php foreach ($pages as $p): ?>
                            <option value="<?= htmlspecialchars($p['slug']) ?>">
                                <?= htmlspecialchars($p['title']) ?>
                            </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="mb-2" id="field-url" style="display:none">
                        <label class="form-label">URL</label>
                        <input type="text" name="url" class="form-control form-control-sm"
                               placeholder="https://...">
                    </div>
                    <div class="mb-2" id="field-target">
                        <div class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox"
                                   name="target" value="_blank" id="target-blank">
                            <label class="form-check-label small" for="target-blank">
                                In neuem Tab öffnen
                            </label>
                        </div>
                    </div>
                    <?php if ($topItems): ?>
                    <div class="mb-3" id="field-parent">
                        <label class="form-label">Untermenü von</label>
                        <select name="parent_id" class="form-select form-select-sm">
                            <option value="">— kein (oberste Ebene) —</option>
                            <?php foreach ($topItems as $top): ?>
                            <option value="<?= $top['id'] ?>">
                                <?= htmlspecialchars($top['label']) ?>
                            </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <?php endif ?>
                    <button class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-plus-lg"></i> Hinzufügen
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const typeEl  = document.getElementById('item-type');
const pgField = document.getElementById('field-page');
const urlField = document.getElementById('field-url');
const tgtField = document.getElementById('field-target');

function updateFields() {
    const v = typeEl.value;
    pgField.style.display  = v === 'page'   ? '' : 'none';
    urlField.style.display  = v === 'url'    ? '' : 'none';
    tgtField.style.display  = v !== 'header' ? '' : 'none';
}
typeEl?.addEventListener('change', updateFields);
updateFields();
</script>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
