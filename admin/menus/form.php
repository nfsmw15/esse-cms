<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;
use Esse\PageTargets;

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
            $icon      = trim($_POST['icon']   ?? '');

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
                'icon'       => $icon ?: null,
                'page_slug'  => $type === 'page' ? $pageSlug : null,
                'url'        => $type === 'url'  ? $url      : null,
                'target'     => $target,
                'sort_order' => (int)$maxOrder + 10,
            ]);
            header("Location: /admin/menus/edit/{$menuId}");
            exit;

        case 'edit_item':
            $itemId   = (int) ($_POST['item_id'] ?? 0);
            $type     = $_POST['type']       ?? 'page';
            $label    = trim($_POST['label'] ?? '');
            $pageSlug = trim($_POST['page_slug'] ?? '');
            $url      = trim($_POST['url']    ?? '');
            $target   = $_POST['target']      ?? '_self';
            $parentId = (int) ($_POST['parent_id'] ?? 0) ?: null;
            $icon     = trim($_POST['icon']   ?? '');

            if (!in_array($type,   ['page','url','header'], true)) $type   = 'page';
            if (!in_array($target, ['_self','_blank'],       true)) $target = '_self';

            // Prevent item from being its own parent
            if ($parentId === $itemId) $parentId = null;

            if ($itemId && $label) {
                DB::update($ti, [
                    'type'      => $type,
                    'label'     => $label,
                    'icon'      => $icon ?: null,
                    'page_slug' => $type === 'page' ? $pageSlug : null,
                    'url'       => $type === 'url'  ? $url      : null,
                    'target'    => $target,
                    'parent_id' => $parentId,
                ], ['id' => $itemId, 'menu_id' => $menuId]);
            }
            header("Location: /admin/menus/edit/{$menuId}");
            exit;

        case 'indent': // make child of previous sibling
        case 'dedent': // make top-level
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $item   = DB::fetch("SELECT * FROM `{$ti}` WHERE id = ? AND menu_id = ?", [$itemId, $menuId]);
            if ($item) {
                if ($action === 'dedent') {
                    DB::update($ti, ['parent_id' => null], ['id' => $itemId]);
                } else {
                    // Find the item directly above in the same level
                    $prev = DB::fetch(
                        "SELECT * FROM `{$ti}` WHERE menu_id = ? AND parent_id IS NULL AND sort_order < ? ORDER BY sort_order DESC LIMIT 1",
                        [$menuId, $item['sort_order']]
                    );
                    if ($prev && $prev['id'] !== $itemId) {
                        $maxOrder = (int) DB::value(
                            "SELECT COALESCE(MAX(sort_order),0) FROM `{$ti}` WHERE menu_id = ? AND parent_id = ?",
                            [$menuId, $prev['id']]
                        );
                        DB::update($ti, ['parent_id' => $prev['id'], 'sort_order' => $maxOrder + 10], ['id' => $itemId]);
                    }
                }
            }
            header("Location: /admin/menus/edit/{$menuId}");
            exit;

        case 'reorder':
            // Expects: items=[[id,parent_id,sort_order], ...]
            $items = json_decode($_POST['items'] ?? '[]', true);
            foreach ($items as $row) {
                if (!is_array($row) || !isset($row['id'])) continue;
                DB::update($ti, [
                    'sort_order' => (int) ($row['order'] ?? 0),
                    'parent_id'  => ($row['parent_id'] ?? 0) ?: null,
                ], ['id' => (int) $row['id'], 'menu_id' => $menuId]);
            }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;

        case 'toggle_item':
            $itemId = (int) ($_POST['item_id'] ?? 0);
            if ($itemId) {
                $item = DB::fetch("SELECT active FROM `{$ti}` WHERE id = ? AND menu_id = ?", [$itemId, $menuId]);
                if ($item) {
                    $newActive = $item['active'] ? 0 : 1;
                    DB::update($ti, ['active' => $newActive], ['id' => $itemId]);
                    // Also toggle all children
                    DB::query("UPDATE `{$ti}` SET active = ? WHERE parent_id = ? AND menu_id = ?",
                        [$newActive, $itemId, $menuId]);
                }
            }
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

$pages         = PageTargets::publishedPages();
$corePages     = PageTargets::corePages();
$pluginPages   = PageTargets::pluginPages();
$allTopItems   = $topItems; // passed to itemEditForm for parent selector

function itemButtons(array $item, int $menuId): string
{
    $csrf = \Esse\Auth::csrfToken();
    $id   = $item['id'];
    $out  = '';
    foreach (['move_up' => '↑', 'move_down' => '↓'] as $mv => $lbl) {
        $out .= "<form method='post' action='/admin/menus/edit/{$menuId}' class='d-inline'>"
              . "<input type='hidden' name='_csrf' value='{$csrf}'>"
              . "<input type='hidden' name='_action' value='{$mv}'>"
              . "<input type='hidden' name='item_id' value='{$id}'>"
              . "<button class='btn btn-sm btn-outline-secondary py-0 px-1'>{$lbl}</button></form>";
    }
    $out .= "<form method='post' action='/admin/menus/edit/{$menuId}' class='d-inline'"
          . " onsubmit='return confirm(\"Eintrag löschen?\")'>"
          . "<input type='hidden' name='_csrf' value='{$csrf}'>"
          . "<input type='hidden' name='_action' value='delete_item'>"
          . "<input type='hidden' name='item_id' value='{$id}'>"
          . "<button class='btn btn-sm btn-outline-danger py-0 px-1'><i class='bi bi-trash'></i></button></form>";
    return $out;
}

function itemEditForm(array $item, int $menuId, array $pages, array $allTopItems = []): string
{
    $csrf  = \Esse\Auth::csrfToken();
    $id    = $item['id'];
    $type  = htmlspecialchars($item['type']);
    $label = htmlspecialchars($item['label']);
    $icon  = htmlspecialchars($item['icon'] ?? '');

    $url   = htmlspecialchars($item['url'] ?? '');

    $pageOpts = "<option value=''>— wählen —</option>";
    if ($pages) {
        $pageOpts .= "<optgroup label='CMS-Seiten'>";
        foreach ($pages as $p) {
            $sel = $item['page_slug'] === $p['slug'] ? ' selected' : '';
            $pageOpts .= "<option value='" . htmlspecialchars($p['slug']) . "'{$sel}>"
                       . htmlspecialchars($p['title']) . "</option>";
        }
        $pageOpts .= "</optgroup>";
    }
    $corePages = PageTargets::corePages();
    if ($corePages) {
        $pageOpts .= "<optgroup label='Standardseiten'>";
        foreach ($corePages as $p) {
            $sel = $item['page_slug'] === $p['slug'] ? ' selected' : '';
            $pageOpts .= "<option value='" . htmlspecialchars($p['slug']) . "'{$sel}>"
                       . htmlspecialchars($p['title']) . "</option>";
        }
        $pageOpts .= "</optgroup>";
    }
    $pluginPages = \Esse\Plugin::getRegisteredPages();
    if ($pluginPages) {
        $pageOpts .= "<optgroup label='Plugin-Seiten'>";
        foreach ($pluginPages as $pp) {
            $sel = $item['page_slug'] === $pp['slug'] ? ' selected' : '';
            $pageOpts .= "<option value='" . htmlspecialchars($pp['slug']) . "'{$sel}>"
                       . htmlspecialchars($pp['title']) . " (" . htmlspecialchars($pp['plugin_name']) . ")</option>";
        }
        $pageOpts .= "</optgroup>";
    }

    $blankChecked = $item['target'] === '_blank' ? ' checked' : '';
    $currentParent = (int) ($item['parent_id'] ?? 0);

    // Parent selector: only top-level non-self items
    $parentOpts = "<option value=''>— Hauptebene —</option>";
    foreach ($allTopItems as $top) {
        if ($top['id'] === $id) continue; // can't be own parent
        $sel = $currentParent === $top['id'] ? ' selected' : '';
        $parentOpts .= "<option value='{$top['id']}'{$sel}>" . htmlspecialchars($top['label']) . "</option>";
    }

    return "<form method='post' action='/admin/menus/edit/{$menuId}' class='border rounded p-2 bg-dark'>"
         . "<input type='hidden' name='_csrf' value='{$csrf}'>"
         . "<input type='hidden' name='_action' value='edit_item'>"
         . "<input type='hidden' name='item_id' value='{$id}'>"
         . "<div class='row g-2 align-items-end'>"
         . "<div class='col-sm-3'><label class='form-label small'>Typ</label>"
         . "<select name='type' class='form-select form-select-sm item-type-sel'>"
         . "<option value='page'" . ($type === 'page' ? ' selected' : '') . ">Seite</option>"
         . "<option value='url'"  . ($type === 'url'  ? ' selected' : '') . ">URL</option>"
         . "<option value='header'" . ($type === 'header' ? ' selected' : '') . ">Trenner</option>"
         . "</select></div>"
         . "<div class='col-sm-3'><label class='form-label small'>Label</label>"
         . "<input type='text' name='label' class='form-control form-control-sm' value='{$label}' required></div>"
         . "<div class='col-sm-3'><label class='form-label small'>Icon <small class=\"text-secondary\">(opt.)</small></label>"
         . "<div class='input-group input-group-sm'>"
         . "<span class='input-group-text esse-icon-preview px-1' data-for='icon-{$id}'"
         .      " style='cursor:pointer;min-width:34px;justify-content:center'"
         .      " onclick='esseOpenIconPicker(document.getElementById(\"icon-{$id}\"))'"
         .      " title='Icon wählen'>"
         . "<i class='bi bi-grid-3x3-gap' style='opacity:.35'></i>"
         . "</span>"
         . "<input type='text' name='icon' id='icon-{$id}' class='form-control form-control-sm font-monospace'"
         .      " value='{$icon}' placeholder='z.B. house' data-icon-preview='1' oninput='esseUpdatePreview(this)'>"
         . "</div></div>"
         . "<div class='col-sm-4 field-page" . ($type !== 'page' ? ' d-none' : '') . "'>"
         . "<label class='form-label small'>Seite</label>"
         . "<select name='page_slug' class='form-select form-select-sm'>{$pageOpts}</select></div>"
         . "<div class='col-sm-4 field-url" . ($type !== 'url' ? ' d-none' : '') . "'>"
         . "<label class='form-label small'>URL</label>"
         . "<input type='text' name='url' class='form-control form-control-sm' value='{$url}'></div>"
         . "<div class='col-sm-3'><label class='form-label small'>Ebene</label>"
         . "<select name='parent_id' class='form-select form-select-sm'>{$parentOpts}</select></div>"
         . "<div class='col-sm-2'><div class='form-check mt-3'>"
         . "<input class='form-check-input' type='checkbox' name='target' value='_blank' id='t{$id}'{$blankChecked}>"
         . "<label class='form-check-label small' for='t{$id}'>Neuer Tab</label></div></div>"
         . "<div class='col-sm-1'><button class='btn btn-primary btn-sm w-100'>✓</button></div>"
         . "</div></form>";
}

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
                <div id="sortable-top" class="list-group list-group-flush">
                <?php foreach ($topItems as $item):
                    $editId = 'edit-' . $item['id']; ?>

                    <div class="list-group-item px-3 py-2 <?= empty($item['active']) ? 'opacity-50' : '' ?>"
                         data-id="<?= $item['id'] ?>">
                        <div class="d-flex align-items-center gap-2">
                            <span class="drag-handle text-secondary" style="cursor:grab" title="Verschieben">
                                <i class="bi bi-grip-vertical"></i>
                            </span>
                            <?php if (!empty($item['icon'])): ?>
                            <i class="bi bi-<?= htmlspecialchars($item['icon']) ?>" style="font-size:1rem" title="<?= htmlspecialchars($item['icon']) ?>"></i>
                            <?php else: ?>
                            <i class="bi bi-image text-secondary" style="opacity:.25;font-size:1rem" title="Kein Icon"></i>
                            <?php endif ?>
                            <span class="fw-semibold flex-grow-1 <?= empty($item['active']) ? 'text-decoration-line-through text-secondary' : '' ?>">
                                <?= htmlspecialchars($item['label']) ?>
                                <?php if ($item['type'] === 'page' && $item['page_slug']): ?>
                                    <small class="text-secondary fw-normal"><?= htmlspecialchars(PageTargets::redirectUrl((string) $item['page_slug'])) ?></small>
                                <?php elseif ($item['type'] === 'url' && $item['url']): ?>
                                    <small class="text-secondary fw-normal"><?= htmlspecialchars($item['url']) ?></small>
                                <?php endif ?>
                            </span>
                            <?php /* Toggle active */ ?>
                            <form method="post" action="/admin/menus/edit/<?= $menuId ?>" class="d-inline">
                                <input type="hidden" name="_csrf"    value="<?= Auth::csrfToken() ?>">
                                <input type="hidden" name="_action"  value="toggle_item">
                                <input type="hidden" name="item_id"  value="<?= $item['id'] ?>">
                                <button class="btn btn-sm <?= empty($item['active']) ? 'btn-outline-success' : 'btn-outline-secondary' ?>"
                                        title="<?= empty($item['active']) ? 'Aktivieren' : 'Deaktivieren' ?>">
                                    <i class="bi bi-<?= empty($item['active']) ? 'eye' : 'eye-slash' ?>"></i>
                                </button>
                            </form>
                            <?php /* Indent (→) — only if there's an item above */ ?>
                            <form method="post" action="/admin/menus/edit/<?= $menuId ?>" class="d-inline" title="Einrücken (Unterebene)">
                                <input type="hidden" name="_csrf"    value="<?= Auth::csrfToken() ?>">
                                <input type="hidden" name="_action"  value="indent">
                                <input type="hidden" name="item_id"  value="<?= $item['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" title="→ Unterebene">
                                    <i class="bi bi-arrow-bar-right"></i>
                                </button>
                            </form>
                            <button class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="collapse" data-bs-target="#<?= $editId ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="post" action="/admin/menus/edit/<?= $menuId ?>" class="d-inline"
                                  onsubmit="return confirm('Eintrag löschen?')">
                                <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
                                <input type="hidden" name="_action" value="delete_item">
                                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>

                        <div class="collapse mt-2" id="<?= $editId ?>">
                            <?php echo itemEditForm($item, $menuId, $pages, $allTopItems); ?>
                        </div>

                        <?php if (!empty($item['children'])): ?>
                        <div class="sortable-children mt-2 ps-3 border-start border-secondary"
                             data-parent-id="<?= $item['id'] ?>">
                        <?php foreach ($item['children'] as $child):
                            $childEditId = 'edit-' . $child['id']; ?>
                        <div class="d-flex align-items-center gap-2 py-1 <?= empty($child['active']) ? 'opacity-50' : '' ?>"
                             data-child-id="<?= $child['id'] ?>">
                            <span class="drag-handle text-secondary" style="cursor:grab">
                                <i class="bi bi-grip-vertical"></i>
                            </span>
                            <i class="bi bi-arrow-return-right text-secondary small"></i>
                            <?php if (!empty($child['icon'])): ?>
                            <i class="bi bi-<?= htmlspecialchars($child['icon']) ?>" style="font-size:.9rem" title="<?= htmlspecialchars($child['icon']) ?>"></i>
                            <?php else: ?>
                            <i class="bi bi-image text-secondary" style="opacity:.25;font-size:.9rem" title="Kein Icon"></i>
                            <?php endif ?>
                            <span class="small flex-grow-1 <?= empty($child['active']) ? 'text-decoration-line-through text-secondary' : '' ?>">
                                <?= htmlspecialchars($child['label']) ?>
                                <?php if ($child['page_slug']): ?>
                                    <small class="text-secondary"><?= htmlspecialchars(PageTargets::redirectUrl((string) $child['page_slug'])) ?></small>
                                <?php elseif ($child['url']): ?>
                                    <small class="text-secondary"><?= htmlspecialchars($child['url']) ?></small>
                                <?php endif ?>
                            </span>
                            <?php /* Toggle active */ ?>
                            <form method="post" action="/admin/menus/edit/<?= $menuId ?>" class="d-inline">
                                <input type="hidden" name="_csrf"    value="<?= Auth::csrfToken() ?>">
                                <input type="hidden" name="_action"  value="toggle_item">
                                <input type="hidden" name="item_id"  value="<?= $child['id'] ?>">
                                <button class="btn btn-sm py-0 <?= empty($child['active']) ? 'btn-outline-success' : 'btn-outline-secondary' ?>"
                                        title="<?= empty($child['active']) ? 'Aktivieren' : 'Deaktivieren' ?>">
                                    <i class="bi bi-<?= empty($child['active']) ? 'eye' : 'eye-slash' ?>"></i>
                                </button>
                            </form>
                            <?php /* Dedent (←) */ ?>
                            <form method="post" action="/admin/menus/edit/<?= $menuId ?>" class="d-inline" title="Ausrücken (Hauptebene)">
                                <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
                                <input type="hidden" name="_action" value="dedent">
                                <input type="hidden" name="item_id" value="<?= $child['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary py-0" title="← Hauptebene">
                                    <i class="bi bi-arrow-bar-left"></i>
                                </button>
                            </form>
                            <button class="btn btn-sm btn-outline-primary py-0"
                                    data-bs-toggle="collapse" data-bs-target="#<?= $childEditId ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="post" action="/admin/menus/edit/<?= $menuId ?>" class="d-inline"
                                  onsubmit="return confirm('Eintrag löschen?')">
                                <input type="hidden" name="_csrf"   value="<?= Auth::csrfToken() ?>">
                                <input type="hidden" name="_action" value="delete_item">
                                <input type="hidden" name="item_id" value="<?= $child['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                        <div class="collapse ps-4 mt-1" id="<?= $childEditId ?>">
                            <?php echo itemEditForm($child, $menuId, $pages, $allTopItems); ?>
                        </div>
                        <?php endforeach ?>
                        </div>
                        <?php endif ?>
                    </div>
                <?php endforeach ?>
                </div>
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
                    <div class="mb-2">
                        <label class="form-label">Icon <small class="text-secondary">(optional)</small></label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text esse-icon-preview px-2"
                                  data-for="add-item-icon"
                                  style="cursor:pointer;min-width:34px;justify-content:center"
                                  onclick="esseOpenIconPicker(document.getElementById('add-item-icon'))"
                                  title="Icon wählen">
                                <i class="bi bi-grid-3x3-gap" style="opacity:.35"></i>
                            </span>
                            <input type="text" name="icon" id="add-item-icon"
                                   class="form-control form-control-sm font-monospace"
                                   placeholder="z.B. house"
                                   data-icon-preview="1"
                                   oninput="esseUpdatePreview(this)">
                        </div>
                    </div>
                    <div class="mb-2" id="field-page">
                        <label class="form-label">Seite</label>
                        <select name="page_slug" class="form-select form-select-sm">
                            <option value="">— wählen —</option>
                            <?php if ($pages): ?>
                            <optgroup label="CMS-Seiten">
                            <?php foreach ($pages as $p): ?>
                            <option value="<?= htmlspecialchars($p['slug']) ?>">
                                <?= htmlspecialchars($p['title']) ?>
                            </option>
                            <?php endforeach ?>
                            </optgroup>
                            <?php endif ?>
                            <?php if ($corePages): ?>
                            <optgroup label="Standardseiten">
                            <?php foreach ($corePages as $p): ?>
                            <option value="<?= htmlspecialchars($p['slug']) ?>">
                                <?= htmlspecialchars($p['title']) ?>
                            </option>
                            <?php endforeach ?>
                            </optgroup>
                            <?php endif ?>
                            <?php if ($pluginPages): ?>
                            <optgroup label="Plugin-Seiten">
                            <?php foreach ($pluginPages as $pp): ?>
                            <option value="<?= htmlspecialchars($pp['slug']) ?>">
                                <?= htmlspecialchars($pp['title']) ?> (<?= htmlspecialchars($pp['plugin_name']) ?>)
                            </option>
                            <?php endforeach ?>
                            </optgroup>
                            <?php endif ?>
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

// Handle type switcher in inline edit forms
document.addEventListener('change', e => {
    if (!e.target.classList.contains('item-type-sel')) return;
    const form = e.target.closest('form');
    form.querySelector('.field-page')?.classList.toggle('d-none', e.target.value !== 'page');
    form.querySelector('.field-url')?.classList.toggle('d-none',  e.target.value !== 'url');
});

</script>
<?php require __DIR__ . '/../partials/icon-picker.php'; ?>
<?php
$content = ob_get_clean();
$csrf    = Auth::csrfToken();
$extraScripts = '<script src="/public/vendor/sortable/Sortable.min.js"></script>
<script>
// Drag & Drop init — runs after SortableJS is loaded
(function() {
    const csrf     = ' . json_encode($csrf) . ';
    const reordUrl = "/admin/menus/edit/' . $menuId . '";

    function collectOrder() {
        const items = [];
        let order = 10;
        document.querySelectorAll("#sortable-top > [data-id]").forEach(el => {
            items.push({ id: parseInt(el.dataset.id), parent_id: 0, order });
            order += 10;
            let childOrder = 10;
            el.querySelectorAll("[data-child-id]").forEach(ch => {
                items.push({ id: parseInt(ch.dataset.childId), parent_id: parseInt(el.dataset.id), order: childOrder });
                childOrder += 10;
            });
        });
        return items;
    }

    function saveOrder() {
        const fd = new FormData();
        fd.append("_csrf", csrf);
        fd.append("_action", "reorder");
        fd.append("items", JSON.stringify(collectOrder()));
        fetch(reordUrl, { method: "POST", body: fd })
            .then(r => r.json())
            .catch(() => {});
    }

    const topList = document.getElementById("sortable-top");
    if (!topList) return;

    Sortable.create(topList, {
        handle: ".drag-handle",
        animation: 150,
        onEnd: saveOrder,
    });

    document.querySelectorAll(".sortable-children").forEach(el => {
        Sortable.create(el, {
            handle: ".drag-handle",
            animation: 150,
            onEnd: saveOrder,
        });
    });
})();
</script>';
require dirname(__DIR__) . '/layout.php';
