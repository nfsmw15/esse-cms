<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;
use Esse\PageVisibility;
use Esse\Plugin;

// ── AJAX: save visibility ─────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!Auth::verifyCsrf()) { echo json_encode(['error' => 'csrf']); exit; }

    $action = $_POST['_action'] ?? '';

    if ($action === 'save_visibility') {
        $slug     = preg_replace('/[^a-z0-9\-]/', '', ltrim($_POST['slug'] ?? '', '/'));
        $pageType = $_POST['page_type'] ?? 'cms';
        $vis      = $_POST['visibility'] ?? 'public';
        $roles    = is_array($_POST['roles'] ?? null) ? $_POST['roles'] : [];

        if (!$slug || !in_array($vis, PageVisibility::VALUES, true)) {
            echo json_encode(['error' => 'invalid']); exit;
        }
        $roles = array_values(array_filter(
            array_map(fn($r) => preg_replace('/[^a-z0-9\-]/', '', (string) $r), $roles)
        ));

        if ($pageType === 'cms') {
            PageVisibility::saveCmsPage($slug, $vis, $roles);
        } else {
            PageVisibility::savePage($slug, $vis, $roles);
        }

        echo json_encode(['success' => true]); exit;
    }

    echo json_encode(['error' => 'unknown action']); exit;
}

// ── Load data ─────────────────────────────────────────────────────────────────

$t  = DB::table('pages');
$tu = DB::table('users');
$tr = DB::table('roles');

$filter  = $_GET['status'] ?? 'all';
$allCmsPages = DB::fetchAll(
    "SELECT p.*, u.display_name
       FROM `{$t}` p
  LEFT JOIN `{$tu}` u ON u.id = p.author_id
      ORDER BY p.updated_at DESC"
);

$filtered = $filter === 'all'
    ? $allCmsPages
    : array_values(array_filter($allCmsPages, fn($p) => $p['status'] === $filter));

// Plugin pages
$pluginPages = Plugin::getRegisteredPages();

// Standard pages (hard-coded — profil and registrieren are editable)
$standardPages = [
    ['slug' => 'profil',       'title' => 'Mein Profil',  'icon' => 'person-circle',     'default_vis' => 'registered', 'editable' => true],
    ['slug' => 'registrieren', 'title' => 'Registrieren', 'icon' => 'person-plus',        'default_vis' => 'guest_only', 'editable' => true],
    ['slug' => 'login',        'title' => 'Login',        'icon' => 'box-arrow-in-right', 'default_vis' => 'public',     'editable' => false],
    ['slug' => 'abmelden',     'title' => 'Abmelden',     'icon' => 'box-arrow-right',    'default_vis' => 'public',     'editable' => false],
];

// Load all role assignments in one query
$tpr = DB::table('page_roles');
$allPageRoles = [];
foreach (DB::fetchAll("SELECT `page_slug`, `role_slug` FROM `{$tpr}`") as $row) {
    $allPageRoles[$row['page_slug']][] = $row['role_slug'];
}

// Roles for modal (exclude forge + guest)
$modalRoles = DB::fetchAll(
    "SELECT `slug`, `label` FROM `{$tr}` WHERE `slug` NOT IN ('forge','guest') ORDER BY `is_default` DESC, `label` ASC"
);

// Flash message
$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// ── Helper: render visibility badge ──────────────────────────────────────────

function visBadge(string $slug, string $pageType, string $vis, array $roles, string $title, bool $editable = true): string
{
    $colors = ['public' => 'success', 'guest_only' => 'secondary', 'registered' => 'primary', 'roles' => 'warning'];
    $labels = PageVisibility::LABELS;
    $color  = $colors[$vis] ?? 'secondary';
    $label  = htmlspecialchars($labels[$vis] ?? $vis);
    $slugH  = htmlspecialchars($slug);
    $typeH  = htmlspecialchars($pageType);
    $rolesJ = htmlspecialchars(json_encode($roles));
    $titleJ = htmlspecialchars(addslashes($title));

    if (!$editable) {
        return "<span class=\"badge bg-{$color}\">{$label}</span>";
    }

    return "<span class=\"badge bg-{$color} vis-badge\" style=\"cursor:pointer\""
         . " data-vis-badge=\"{$slugH}\""
         . " data-vis=\"{$vis}\""
         . " data-roles=\"{$rolesJ}\""
         . " onclick=\"openVisModal('{$slugH}','{$typeH}',this.dataset.vis,JSON.parse(this.dataset.roles),'{$titleJ}')\">"
         . "{$label}</span>";
}

// ── Page setup ────────────────────────────────────────────────────────────────

$pageTitle = 'Seiten';
$activeNav = 'pages';

ob_start();
?>

<?php if ($flash): ?>
<div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex gap-2">
        <?php foreach (['all' => 'Alle', 'published' => 'Live', 'draft' => 'Entwürfe'] as $val => $lbl): ?>
        <a href="/admin/pages?status=<?= $val ?>"
           class="btn btn-sm <?= $filter === $val ? 'btn-primary' : 'btn-outline-secondary' ?>">
            <?= $lbl ?>
        </a>
        <?php endforeach ?>
    </div>
    <a href="/admin/pages/create" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> Neue Seite
    </a>
</div>

<!-- ── CMS-Seiten ───────────────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header py-2 d-flex align-items-center gap-2">
        <i class="bi bi-file-earmark-text text-secondary"></i>
        <strong>CMS-Seiten</strong>
    </div>
    <div class="card-body p-0">
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
            <?php foreach ($filtered as $p):
                $pVis   = PageVisibility::forCmsPage($p);
                $pRoles = $allPageRoles[$p['slug']] ?? [];
            ?>
            <tr>
                <td>
                    <a href="/admin/pages/edit/<?= htmlspecialchars($p['slug']) ?>"
                       class="text-white text-decoration-none fw-semibold">
                        <?= htmlspecialchars($p['title']) ?>
                    </a>
                </td>
                <td><code class="text-secondary small">/<?= htmlspecialchars($p['slug']) ?></code></td>
                <td>
                    <?= $p['type'] === 'php'
                        ? '<span class="badge bg-warning text-dark">PHP</span>'
                        : '<span class="badge bg-secondary">Standard</span>' ?>
                </td>
                <td><?= visBadge($p['slug'], 'cms', $pVis, $pRoles, $p['title']) ?></td>
                <td>
                    <?= $p['status'] === 'published'
                        ? '<span class="badge bg-success">Live</span>'
                        : '<span class="badge bg-secondary">Entwurf</span>' ?>
                </td>
                <td class="text-secondary small"><?= htmlspecialchars($p['display_name'] ?? '—') ?></td>
                <td class="text-secondary small"><?= date('d.m.Y H:i', strtotime($p['updated_at'])) ?></td>
                <td class="text-end">
                    <a href="/admin/pages/edit/<?= htmlspecialchars($p['slug']) ?>"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <form method="post" action="/admin/pages/delete/<?= htmlspecialchars($p['slug']) ?>"
                          class="d-inline"
                          onsubmit="return confirm('Seite \'<?= htmlspecialchars(addslashes($p['title'])) ?>\' wirklich löschen?')">
                        <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
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

<!-- ── Plugin-Seiten ────────────────────────────────────────────────────────── -->
<?php if ($pluginPages): ?>
<div class="card mb-4">
    <div class="card-header py-2 d-flex align-items-center gap-2">
        <i class="bi bi-puzzle text-secondary"></i>
        <strong>Plugin-Seiten</strong>
        <small class="text-secondary ms-1">— registriert durch Plugins; Sichtbarkeit hier überschreiben</small>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr>
                <th>Titel</th>
                <th>URL</th>
                <th>Plugin</th>
                <th>Sichtbarkeit</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($pluginPages as $pp):
                $ppVis   = PageVisibility::forPage($pp['slug'], $pp['visibility'] ?: 'public');
                $ppRoles = $allPageRoles[$pp['slug']] ?? [];
            ?>
            <tr class="align-middle">
                <td>
                    <i class="bi <?= htmlspecialchars($pp['icon']) ?> text-secondary me-1"></i>
                    <?= htmlspecialchars($pp['title']) ?>
                </td>
                <td><code class="text-secondary small">/<?= htmlspecialchars($pp['slug']) ?></code></td>
                <td><span class="badge bg-secondary"><?= htmlspecialchars($pp['plugin_name']) ?></span></td>
                <td><?= visBadge($pp['slug'], 'plugin', $ppVis, $ppRoles, $pp['title']) ?></td>
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

<!-- ── Standardseiten ───────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header py-2 d-flex align-items-center gap-2">
        <i class="bi bi-layout-text-window text-secondary"></i>
        <strong>Standardseiten</strong>
        <small class="text-secondary ms-1">— systemseitig, Sichtbarkeit anpassbar</small>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr>
                <th>Titel</th>
                <th>URL</th>
                <th>Sichtbarkeit</th>
            </tr></thead>
            <tbody>
            <?php foreach ($standardPages as $sp):
                $spVis   = PageVisibility::forPage($sp['slug'], $sp['default_vis']);
                $spRoles = $allPageRoles[$sp['slug']] ?? [];
            ?>
            <tr class="align-middle">
                <td>
                    <i class="bi bi-<?= htmlspecialchars($sp['icon']) ?> text-secondary me-1"></i>
                    <?= htmlspecialchars($sp['title']) ?>
                </td>
                <td><code class="text-secondary small">/<?= htmlspecialchars($sp['slug']) ?></code></td>
                <td>
                    <?= visBadge($sp['slug'], 'standard', $spVis, $spRoles, $sp['title'], $sp['editable']) ?>
                    <?php if (!$sp['editable']): ?>
                    <small class="text-secondary ms-1">fest</small>
                    <?php endif ?>
                </td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Sichtbarkeits-Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="visModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">
                    Sichtbarkeit: <span id="vm-title" class="text-white"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="vm-slug">
                <input type="hidden" id="vm-type">
                <div class="mb-3">
                    <label class="form-label">Sichtbarkeit</label>
                    <select class="form-select" id="vm-vis" onchange="vmUpdateRoles()">
                        <option value="public">Öffentlich — für alle</option>
                        <option value="guest_only">Nur Gäste — nicht eingeloggt</option>
                        <option value="registered">Alle Eingeloggten</option>
                        <option value="roles">Rollen — bestimmte Rollen wählen</option>
                    </select>
                </div>
                <div id="vm-roles-panel" style="display:none">
                    <label class="form-label">Erlaubte Rollen</label>
                    <div id="vm-roles-list"></div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                <button type="button" class="btn btn-primary" id="vm-save" onclick="vmSave()">Speichern</button>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();

$extraScripts = '<script>
const CSRF      = ' . json_encode(Auth::csrfToken()) . ';
const ALL_ROLES = ' . json_encode($modalRoles) . ';

let _visModal = null;

function openVisModal(slug, pageType, vis, roles, title) {
    document.getElementById("vm-slug").value  = slug;
    document.getElementById("vm-type").value  = pageType;
    document.getElementById("vm-vis").value   = vis;
    document.getElementById("vm-title").textContent = title;

    const list = document.getElementById("vm-roles-list");
    list.innerHTML = "";
    ALL_ROLES.forEach(role => {
        const checked = roles.includes(role.slug) ? "checked" : "";
        list.innerHTML += `<div class="form-check">
            <input class="form-check-input" type="checkbox"
                   id="vmr-${role.slug}" value="${role.slug}" ${checked}>
            <label class="form-check-label" for="vmr-${role.slug}">${role.label}</label>
        </div>`;
    });

    vmUpdateRoles();
    _visModal = new bootstrap.Modal(document.getElementById("visModal"));
    _visModal.show();
}

function vmUpdateRoles() {
    document.getElementById("vm-roles-panel").style.display =
        document.getElementById("vm-vis").value === "roles" ? "" : "none";
}

async function vmSave() {
    const slug     = document.getElementById("vm-slug").value;
    const pageType = document.getElementById("vm-type").value;
    const vis      = document.getElementById("vm-vis").value;
    const roles    = [...document.querySelectorAll("#vm-roles-list input:checked")]
                       .map(el => el.value);

    const btn = document.getElementById("vm-save");
    btn.disabled = true;

    const fd = new FormData();
    fd.append("_csrf",      CSRF);
    fd.append("_action",    "save_visibility");
    fd.append("slug",       slug);
    fd.append("page_type",  pageType);
    fd.append("visibility", vis);
    roles.forEach(r => fd.append("roles[]", r));

    try {
        const res  = await fetch("/admin/pages", { method: "POST", body: fd });
        const data = await res.json();

        if (data.success) {
            // Update badge in DOM
            const badge = document.querySelector(`[data-vis-badge="${slug}"]`);
            if (badge) {
                const labels = {public:"Öffentlich", guest_only:"Nur Gäste", registered:"Alle Eingeloggten", roles:"Rollen"};
                const colors = {public:"success", guest_only:"secondary", registered:"primary", roles:"warning"};
                badge.textContent    = labels[vis] || vis;
                badge.className      = `badge bg-${colors[vis] || "secondary"} vis-badge`;
                badge.dataset.vis    = vis;
                badge.dataset.roles  = JSON.stringify(roles);
            }
            bootstrap.Modal.getInstance(document.getElementById("visModal")).hide();
        } else {
            alert(data.error || "Fehler beim Speichern.");
        }
    } finally {
        btn.disabled = false;
    }
}
</script>';

require dirname(__DIR__) . '/layout.php';
