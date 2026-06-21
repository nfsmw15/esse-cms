<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;
use Esse\Flash;
use Esse\PageVisibility;
use Esse\Plugin;

// ── POST handler ──────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $action = $_POST['_action'] ?? '';

    // AJAX: toggle page visibility
    if ($action === 'save_visibility') {
        header('Content-Type: application/json');
        $slug     = preg_replace('#[^a-z0-9\-/]#', '', ltrim($_POST['slug'] ?? '', '/'));
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

    // AJAX: assign page targets (Startseite, Login-Start, Logout, Fehler)
    if ($action === 'save_page_target') {
        header('Content-Type: application/json');
        $slug    = preg_replace('#[^a-z0-9\-/]#', '', ltrim($_POST['slug'] ?? '', '/'));
        $targets = is_array($_POST['targets'] ?? null) ? $_POST['targets'] : [];

        $allowed = ['homepage_slug', 'login_homepage_slug', 'logout_page_slug', 'error_page_slug'];
        $targets = array_values(array_filter($targets, fn($t) => in_array($t, $allowed, true)));

        if (!$slug) { echo json_encode(['error' => 'invalid']); exit; }

        $ts = DB::table('settings');

        foreach ($allowed as $key) {
            if (in_array($key, $targets, true)) {
                DB::query(
                    "INSERT INTO `{$ts}` (`key`, `value`) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                    [$key, $slug]
                );
            } else {
                // Only clear if currently pointing to this slug
                DB::query("UPDATE `{$ts}` SET `value` = '' WHERE `key` = ? AND `value` = ?", [$key, $slug]);
            }
        }

        echo json_encode(['success' => true, 'assigned' => $targets]); exit;
    }

    // AJAX: save icon override for plugin/standard page
    if ($action === 'save_page_icon') {
        header('Content-Type: application/json');
        $slug = preg_replace('#[^a-z0-9\-/]#', '', ltrim($_POST['slug'] ?? '', '/'));
        $icon = preg_replace('/[^a-z0-9\-]/', '', trim($_POST['icon'] ?? ''));

        if (!$slug) { echo json_encode(['error' => 'invalid']); exit; }

        PageVisibility::saveIcon($slug, $icon ?: null);
        echo json_encode(['success' => true, 'icon' => $icon]); exit;
    }

    http_response_code(400); exit;
}

// ── Load data ─────────────────────────────────────────────────────────────────

$t  = DB::table('pages');
$tu = DB::table('users');
$tr = DB::table('roles');

$filter      = $_GET['status'] ?? 'all';
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

// Standard pages
$standardPages = [
    ['slug' => 'profil',       'title' => 'Mein Profil',  'icon' => 'person-circle',     'default_vis' => 'registered', 'vis_editable' => true],
    ['slug' => 'registrieren', 'title' => 'Registrieren', 'icon' => 'person-plus',        'default_vis' => 'guest_only', 'vis_editable' => true],
    ['slug' => 'login',        'title' => 'Login',        'icon' => 'box-arrow-in-right', 'default_vis' => 'public',     'vis_editable' => false],
    ['slug' => 'abmelden',     'title' => 'Abmelden',     'icon' => 'box-arrow-right',    'default_vis' => 'public',     'vis_editable' => false],
];

// Page roles (bulk)
$tpr = DB::table('page_roles');
$allPageRoles = [];
foreach (DB::fetchAll("SELECT `page_slug`, `role_slug` FROM `{$tpr}`") as $row) {
    $allPageRoles[$row['page_slug']][] = $row['role_slug'];
}

// Roles for visibility modal
$modalRoles = DB::fetchAll(
    "SELECT `slug`, `label` FROM `{$tr}` WHERE `slug` NOT IN ('forge','guest') ORDER BY `is_default` DESC, `label` ASC"
);

// Page targets — load current assignments + build reverse map (slug → [keys])
$ts         = DB::table('settings');
$ptRows     = DB::fetchAll("SELECT `key`, `value` FROM `{$ts}` WHERE `key` IN ('homepage_slug','login_homepage_slug','logout_page_slug','error_page_slug')");
$ptSettings = array_column($ptRows, 'value', 'key');
$slugTargets = [];
foreach ($ptSettings as $key => $slug) {
    if ($slug) $slugTargets[$slug][] = $key;
}

// Flash
$flash = Flash::consume();

// ── Helpers ───────────────────────────────────────────────────────────────────

function visBadge(string $slug, string $pageType, string $vis, array $roles, string $title, bool $editable = true): string
{
    $colors = ['public' => 'success', 'guest_only' => 'secondary', 'registered' => 'primary', 'roles' => 'warning'];
    $labels = PageVisibility::LABELS;
    $color  = $colors[$vis] ?? 'secondary';
    $label  = htmlspecialchars($labels[$vis] ?? $vis);
    $slugH  = htmlspecialchars($slug);
    $typeH  = htmlspecialchars($pageType);
    $rolesJ = htmlspecialchars(json_encode($roles), ENT_QUOTES, 'UTF-8');
    $titleH = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    if (!$editable) {
        return "<span class=\"badge bg-{$color}\">{$label}</span>";
    }

    return "<span class=\"badge bg-{$color} vis-badge admin-inline-action\""
         . " data-vis-badge=\"{$slugH}\" data-vis=\"{$vis}\" data-roles=\"{$rolesJ}\""
         . " data-page-type=\"{$typeH}\" data-title=\"{$titleH}\">"
         . "{$label}</span>";
}

function iconOverrideBadge(string $slug, string $icon, string $title): string
{
    $slugH  = htmlspecialchars($slug);
    $titleH = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $iconH  = htmlspecialchars($icon);

    $iconHtml = $icon
        ? "<i class=\"bi bi-{$iconH} admin-icon-base\"></i>"
        : "<i class=\"bi bi-image text-secondary admin-icon-faded admin-icon-base\" title=\"Kein Icon\"></i>";

    return "<span class=\"icon-override-btn me-1 admin-inline-action\""
         . " data-icon-slug=\"{$slugH}\" data-icon-current=\"{$iconH}\""
         . " data-title=\"{$titleH}\">"
         . "{$iconHtml}</span>";
}

function targetBadge(string $slug, array $slugTargets): string
{
    $defs     = [
        'homepage_slug'       => ['Startseite',    'success'],
        'login_homepage_slug' => ['Login-Start',   'primary'],
        'logout_page_slug'    => ['Logoutseite',   'info'],
        'error_page_slug'     => ['Fehlerseite',   'danger'],
    ];
    $assigned = $slugTargets[$slug] ?? [];
    $slugH    = htmlspecialchars($slug);
    $dataJ    = htmlspecialchars(json_encode($assigned), ENT_QUOTES, 'UTF-8');

    $inner = $assigned
        ? implode('', array_map(fn($k) => '<span class="badge bg-' . ($defs[$k][1] ?? 'secondary') . ' me-1">' . htmlspecialchars($defs[$k][0] ?? $k) . '</span>', $assigned))
        : '<span class="text-secondary admin-text-xs">—</span>';

    return "<span class=\"target-badge admin-inline-action\""
         . " data-target-badge=\"{$slugH}\" data-assigned=\"{$dataJ}\""
         . ">{$inner}</span>";
}

// ── Page setup ────────────────────────────────────────────────────────────────

$pageTitle = 'Seiten';
$activeNav = 'pages';

ob_start();
?>

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

<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm mb-0 align-middle">
            <thead>
                <tr>
                    <th>Titel</th>
                    <th>Slug</th>
                    <th>Art</th>
                    <th>Sichtbarkeit</th>
                    <th>Verwendung</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>

            <!-- ── CMS-Seiten ──────────────────────────────────────────────── -->
            <tr class="table-active border-top">
                <td colspan="7" class="py-1 px-3">
                    <small class="text-secondary">
                        <i class="bi bi-file-earmark-text me-1"></i><strong class="text-white">CMS-Seiten</strong>
                    </small>
                </td>
            </tr>
            <?php if ($filtered): ?>
            <?php foreach ($filtered as $p):
                $pVis   = PageVisibility::forCmsPage($p);
                $pRoles = $allPageRoles[$p['slug']] ?? [];
            ?>
            <tr>
                <td>
                    <?php if (!empty($p['icon'])): ?>
                    <i class="bi bi-<?= htmlspecialchars($p['icon']) ?> me-1"></i>
                    <?php else: ?>
                    <i class="bi bi-image text-secondary me-1 admin-icon-faded" title="Kein Icon"></i>
                    <?php endif ?>
                    <a href="/admin/pages/edit/<?= htmlspecialchars($p['slug']) ?>"
                       class="text-white text-decoration-none fw-semibold">
                        <?= htmlspecialchars($p['title']) ?>
                    </a>
                </td>
                <td><code class="text-secondary small">/<?= htmlspecialchars($p['slug']) ?></code></td>
                <td>
                    <?= $p['type'] === 'php'
                        ? '<span class="badge bg-warning text-dark">PHP</span>'
                        : '<span class="badge bg-secondary">CMS</span>' ?>
                </td>
                <td><?= visBadge($p['slug'], 'cms', $pVis, $pRoles, $p['title']) ?></td>
                <td><?= targetBadge($p['slug'], $slugTargets) ?></td>
                <td>
                    <?= $p['status'] === 'published'
                        ? '<span class="badge bg-success">Live</span>'
                        : '<span class="badge bg-secondary">Entwurf</span>' ?>
                </td>
                <td class="text-end">
                    <a href="/admin/pages/edit/<?= htmlspecialchars($p['slug']) ?>"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <form method="post" action="/admin/pages/delete/<?= htmlspecialchars($p['slug']) ?>"
                          class="d-inline"
                          data-confirm="Seite '<?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?>' wirklich löschen?">
                        <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach ?>
            <?php else: ?>
            <tr>
                <td colspan="7" class="text-center text-secondary py-3">
                    Keine Seiten gefunden.
                    <a href="/admin/pages/create" class="text-primary">Erste Seite erstellen</a>
                </td>
            </tr>
            <?php endif ?>

            <!-- ── Plugin-Seiten ───────────────────────────────────────────── -->
            <?php if ($pluginPages): ?>
            <tr class="table-active border-top">
                <td colspan="7" class="py-1 px-3">
                    <small class="text-secondary">
                        <i class="bi bi-puzzle me-1"></i><strong class="text-white">Plugin-Seiten</strong>
                        — Sichtbarkeit hier überschreibbar
                    </small>
                </td>
            </tr>
            <?php foreach ($pluginPages as $pp):
                $ppVis      = PageVisibility::forPage($pp['slug'], $pp['visibility'] ?: 'public');
                $ppRoles    = $allPageRoles[$pp['slug']] ?? [];
                $ppIconRaw  = PageVisibility::stripIconPrefix($pp['icon'] ?? '');
                $ppIcon     = PageVisibility::getIcon($pp['slug'], $ppIconRaw);
            ?>
            <tr>
                <td>
                    <?= iconOverrideBadge($pp['slug'], $ppIcon, $pp['title']) ?>
                    <?= htmlspecialchars($pp['title']) ?>
                </td>
                <td><code class="text-secondary small">/<?= htmlspecialchars($pp['slug']) ?></code></td>
                <td><span class="badge bg-info text-dark"><?= htmlspecialchars($pp['plugin_name']) ?></span></td>
                <td><?= visBadge($pp['slug'], 'plugin', $ppVis, $ppRoles, $pp['title']) ?></td>
                <td><?= targetBadge($pp['slug'], $slugTargets) ?></td>
                <td><span class="text-secondary small">—</span></td>
                <td class="text-end">
                    <a href="/<?= htmlspecialchars($pp['slug']) ?>" target="_blank"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach ?>
            <?php endif ?>

            <!-- ── Standardseiten ──────────────────────────────────────────── -->
            <tr class="table-active border-top">
                <td colspan="7" class="py-1 px-3">
                    <small class="text-secondary">
                        <i class="bi bi-layout-text-window me-1"></i><strong class="text-white">Standardseiten</strong>
                        — systemseitig
                    </small>
                </td>
            </tr>
            <?php foreach ($standardPages as $sp):
                $spVis   = PageVisibility::forPage($sp['slug'], $sp['default_vis']);
                $spRoles = $allPageRoles[$sp['slug']] ?? [];
                $spIcon  = PageVisibility::getIcon($sp['slug'], $sp['icon']);
            ?>
            <tr>
                <td>
                    <?= iconOverrideBadge($sp['slug'], $spIcon, $sp['title']) ?>
                    <?= htmlspecialchars($sp['title']) ?>
                </td>
                <td><code class="text-secondary small">/<?= htmlspecialchars($sp['slug']) ?></code></td>
                <td><span class="badge bg-dark border border-secondary">System</span></td>
                <td>
                    <?= visBadge($sp['slug'], 'standard', $spVis, $spRoles, $sp['title'], $sp['vis_editable']) ?>
                    <?php if (!$sp['vis_editable']): ?>
                    <small class="text-secondary ms-1">fest</small>
                    <?php endif ?>
                </td>
                <td><?= targetBadge($sp['slug'], $slugTargets) ?></td>
                <td><span class="text-secondary small">—</span></td>
                <td></td>
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
                <h5 class="modal-title">Sichtbarkeit: <span id="vm-title" class="text-white"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="vm-slug">
                <input type="hidden" id="vm-type">
                <div class="mb-3">
                    <label class="form-label">Sichtbarkeit</label>
                    <select class="form-select" id="vm-vis">
                        <option value="public">Öffentlich — für alle</option>
                        <option value="guest_only">Nur Gäste — nicht eingeloggt</option>
                        <option value="registered">Alle Eingeloggten</option>
                        <option value="roles">Rollen — bestimmte Rollen wählen</option>
                    </select>
                </div>
                <div id="vm-roles-panel" class="admin-hidden">
                    <label class="form-label">Erlaubte Rollen</label>
                    <div id="vm-roles-list"></div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                <button type="button" class="btn btn-primary" id="vm-save">Speichern</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Icon-Override-Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="iconModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">Icon: <span id="im-title" class="text-white"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="im-slug">
                <div class="input-group input-group-sm">
                    <span class="input-group-text esse-icon-preview px-2 admin-icon-preview"
                          data-for="im-icon"
                          data-icon-picker-target="im-icon"
                          title="Icon wählen">
                        <i class="bi bi-grid-3x3-gap admin-icon-muted"></i>
                    </span>
                    <input type="text" id="im-icon" class="form-control form-control-sm font-monospace"
                           placeholder="z.B. people" data-icon-preview="1">
                </div>
                <div class="form-text">Leer lassen = Plugin-Standard</div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Abbrechen</button>
                <button type="button" class="btn btn-primary btn-sm" id="im-save">Speichern</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Verwendungs-Modal ────────────────────────────────────────────────────── -->
<div class="modal fade" id="targetModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">Verwendung</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="tm-slug">
                <p class="text-secondary small mb-3">Für welchen Zweck wird diese Seite genutzt?</p>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" value="homepage_slug" id="tm-c1">
                    <label class="form-check-label" for="tm-c1">
                        Startseite <code class="small">/</code>
                    </label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" value="login_homepage_slug" id="tm-c2">
                    <label class="form-check-label" for="tm-c2">Startseite nach Login</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" value="logout_page_slug" id="tm-c3">
                    <label class="form-check-label" for="tm-c3">Logoutseite</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="error_page_slug" id="tm-c4">
                    <label class="form-check-label" for="tm-c4">Fehlerseite (403 / 404)</label>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Abbrechen</button>
                <button type="button" class="btn btn-primary btn-sm" id="tm-save">Speichern</button>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/partials/icon-picker.php'; ?>

<?php
$content = ob_get_clean();

$extraScriptConfig = array_merge($extraScriptConfig ?? [], ['admin-pages-list-config' => [
    'csrf' => Auth::csrfToken(),
    'roles' => $modalRoles,
]]);
$extraScriptFiles = array_merge($extraScriptFiles ?? [], ['/public/assets/js/admin-pages-list.js']);

require dirname(__DIR__) . '/layout.php';
