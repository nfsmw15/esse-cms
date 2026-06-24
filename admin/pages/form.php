<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;
use Esse\DB;
use Esse\Flash;

// $editSlug is injected by the route closure when editing; null for create
$editSlug ??= null;
$isEdit   = $editSlug !== null;
$t        = DB::table('pages');
$tr       = DB::table('roles');
$page     = null;
$errors   = [];

$formRoles = DB::fetchAll(
    "SELECT `slug`, `label` FROM `{$tr}` WHERE `slug` NOT IN ('forge','guest') ORDER BY `is_default` DESC, `label` ASC"
);

if ($isEdit) {
    $page = DB::fetch("SELECT * FROM `{$t}` WHERE slug = ?", [$editSlug]);
    if (!$page) {
        http_response_code(404);
        echo '404 — Seite nicht gefunden';
        exit;
    }
}

$currentVis      = \Esse\PageVisibility::normalize($page['visibility'] ?? 'public');
$currentVisRoles = $isEdit ? \Esse\PageVisibility::getRoles($page['slug'] ?? '') : [];

// -- POST handling --

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) {
        $errors[] = 'Ungültige Anfrage.';
    } else {
        $title      = trim($_POST['title']      ?? '');
        // Nur Icon-Klassen-Zeichen erlauben (gleiche Regel wie admin/pages/list.php) — sonst
        // landet ein freier Wert direkt im class-Attribut des Preview-<i>-Tags.
        $icon       = preg_replace('/[^a-z0-9\-]/', '', trim($_POST['icon'] ?? ''));
        $hideTitle  = !empty($_POST['hide_title']) ? 1 : 0;
        $slug       = trim($_POST['slug']       ?? '');
        $metaDesc   = trim($_POST['meta_description'] ?? '');
        $content    = $_POST['content']         ?? '';
        $type       = $_POST['type']            ?? 'standard';
        $visibility = $_POST['visibility']      ?? 'public';
        $visRoles   = is_array($_POST['vis_roles'] ?? null) ? $_POST['vis_roles'] : [];
        $status     = $_POST['status']          ?? 'draft';

        // Normalize slug
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $slug));
        $slug = trim($slug, '-');

        // Validate
        if (!$title) $errors[] = 'Titel ist Pflichtfeld.';
        if (!$slug)  $errors[] = 'Slug ist Pflichtfeld.';

        if (!in_array($type,       ['standard', 'php'],                 true)) $type       = 'standard';
        if (!in_array($visibility, \Esse\PageVisibility::VALUES,         true)) $visibility = 'public';
        if (!in_array($status,     ['published', 'draft'],               true)) $status     = 'draft';
        $visRoles = array_values(array_filter(array_map(
            fn($r) => preg_replace('/[^a-z0-9\-]/', '', (string) $r),
            $visRoles
        )));

        // PHP type requires php_upload permission
        if ($type === 'php' && !Auth::can('php_upload')) {
            $errors[] = 'Du hast keine Berechtigung für PHP-Seiten.';
            $type = 'standard';
        }

        // Slug uniqueness check (exclude current page on edit)
        if (!$errors && $slug) {
            $existing = DB::fetch(
                "SELECT id FROM `{$t}` WHERE slug = ?" . ($isEdit ? " AND id != ?" : ''),
                $isEdit ? [$slug, $page['id']] : [$slug]
            );
            if ($existing) $errors[] = "Slug '{$slug}' ist bereits vergeben.";
            if (\Esse\Plugin::isPluginSlug($slug)) {
                $errors[] = "Slug '{$slug}' wird bereits von einem Plugin verwendet. Bitte einen anderen Slug wählen.";
            }
        }

        // Handle PHP file upload
        $filePath = $isEdit ? ($page['file_path'] ?? null) : null;
        if ($type === 'php' && !empty($_FILES['php_file']['tmp_name'])) {
            $uploadedName = $_FILES['php_file']['name'];
            $ext          = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));

            if (($_FILES['php_file']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $errors[] = 'Upload fehlgeschlagen.';
            } elseif (!in_array($ext, ['php', 'html'], true)) {
                $errors[] = 'Nur .php und .html Dateien erlaubt.';
            } else {
                $dest = ESSE_ROOT . '/pages/' . $slug . '.' . $ext;
                if (!move_uploaded_file($_FILES['php_file']['tmp_name'], $dest)) {
                    $errors[] = 'Datei konnte nicht gespeichert werden.';
                } else {
                    // Delete old file if slug or extension changed
                    if ($isEdit && $page['file_path'] && $page['file_path'] !== basename($dest)) {
                        @unlink(ESSE_ROOT . '/pages/' . basename((string)$page['file_path']));
                    }
                    $filePath = $slug . '.' . $ext;
                    AuditLog::record(
                        'php_page_uploaded',
                        Auth::id(),
                        Auth::user()['email'] ?? null,
                        ['slug' => $slug, 'file' => $filePath]
                    );
                }
            }
        }

        if (empty($errors)) {
            $data = [
                'title'      => $title,
                'icon'       => $icon ?: null,
                'hide_title' => $hideTitle,
                'slug'       => $slug,
                'meta_description' => $metaDesc ?: null,
                'content'    => $type === 'standard' ? $content : null,
                'type'       => $type,
                'file_path'  => $type === 'php' ? $filePath : null,
                'visibility' => $visibility,
                'status'     => $status,
                'author_id'  => Auth::id(),
            ];

            if ($isEdit) {
                DB::update($t, $data, ['id' => $page['id']]);
                Flash::set('success', 'Seite gespeichert.');
            } else {
                DB::insert($t, $data);
                Flash::set('success', "Seite '{$title}' erstellt.");
            }

            \Esse\PageVisibility::saveCmsPage($slug, $visibility, $visRoles);

            header('Location: /admin/pages');
            exit;
        }

        // Re-populate on error
        $page = array_merge($page ?? [], compact('title', 'slug', 'content', 'type', 'visibility', 'status'), ['meta_description' => $metaDesc, 'hide_title' => $hideTitle]);
        $currentVis      = $visibility;
        $currentVisRoles = $visRoles;
    }
}

$pageTitle = $isEdit ? 'Seite bearbeiten' : 'Neue Seite';
$activeNav = 'pages';

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="/admin/pages" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
    <?php if ($isEdit && $page['status'] === 'published'): ?>
    <a href="/<?= htmlspecialchars($page['slug']) ?>" target="_blank"
       class="btn btn-sm btn-outline-success">
        <i class="bi bi-box-arrow-up-right"></i> Ansehen
    </a>
    <?php endif ?>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $e): ?>
    <div><?= htmlspecialchars($e) ?></div>
    <?php endforeach ?>
</div>
<?php endif ?>

<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">

    <div class="row g-4">
        <!-- Left: main content -->
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Titel</label>
                        <div class="input-group">
                            <span class="input-group-text esse-icon-preview px-2 admin-icon-preview-lg"
                                  data-for="page-icon"
                                  data-icon-picker-target="page-icon"
                                  title="Icon wählen">
                                <i class="bi bi-grid-3x3-gap admin-icon-muted admin-icon-md"></i>
                            </span>
                            <input type="text" name="icon" id="page-icon"
                                   class="form-control font-monospace admin-short-input"
                                   placeholder="z.B. house"
                                   value="<?= htmlspecialchars($page['icon'] ?? '') ?>"
                                   data-icon-preview="1"
                                   >
                            <input type="text" name="title" id="title" class="form-control"
                                   value="<?= htmlspecialchars($page['title'] ?? '') ?>"
                                   required autofocus placeholder="Seitentitel">
                        </div>
                        <div class="form-text">Optional: Icon-Name, z.B. <code>house</code> — oder Klick auf das Icon-Feld zum Suchen</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Slug</label>
                        <div class="input-group">
                            <span class="input-group-text text-secondary">/</span>
                            <input type="text" name="slug" id="slug" class="form-control font-monospace"
                                   value="<?= htmlspecialchars($page['slug'] ?? '') ?>" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Standard content -->
            <div class="card" id="content-card">
                <div class="card-header py-2">
                    <small class="text-secondary">Inhalt</small>
                </div>
                <div class="card-body p-0">
                    <textarea name="content" id="content"><?= htmlspecialchars($page['content'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- PHP file upload (only if permission) -->
            <?php if (Auth::can('php_upload')): ?>
	            <div class="card mt-3 admin-hidden" id="php-card">
                <div class="card-header py-2 d-flex justify-content-between">
                    <small class="text-secondary">PHP / HTML Datei</small>
                    <?php if ($isEdit && $page['type'] === 'php' && $page['file_path']): ?>
                    <small class="text-success">
                        <i class="bi bi-file-code"></i>
                        <?= htmlspecialchars($page['file_path']) ?>
                    </small>
                    <?php endif ?>
                </div>
                <div class="card-body">
                    <input type="file" name="php_file" class="form-control" accept=".php,.html">
                    <div class="form-text">Datei wird in <code>pages/</code> gespeichert und ist nicht direkt per HTTP erreichbar.</div>
                </div>
            </div>
            <?php endif ?>
        </div>

        <!-- Right: meta -->
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header py-2"><small class="text-secondary">Veröffentlichen</small></div>
                <div class="card-body d-grid gap-2">
                    <button name="status" value="published" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Veröffentlichen
                    </button>
                    <button name="status" value="draft" class="btn btn-outline-secondary">
                        <i class="bi bi-floppy"></i> Als Entwurf speichern
                    </button>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header py-2"><small class="text-secondary">Sichtbarkeit</small></div>
                <div class="card-body">
                    <select name="visibility" id="form-vis-select" class="form-select mb-2">
                        <?php foreach (\Esse\PageVisibility::LABELS as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $currentVis === $val ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                        <?php endforeach ?>
                    </select>
	                    <div id="form-vis-roles" class="<?= $currentVis === 'roles' ? '' : 'admin-hidden' ?>">
                        <small class="text-secondary d-block mb-1">Erlaubte Rollen:</small>
                        <?php foreach ($formRoles as $role): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox"
                                   name="vis_roles[]"
                                   value="<?= htmlspecialchars($role['slug']) ?>"
                                   id="fvr-<?= htmlspecialchars($role['slug']) ?>"
                                   <?= in_array($role['slug'], $currentVisRoles, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="fvr-<?= htmlspecialchars($role['slug']) ?>">
                                <?= htmlspecialchars($role['label']) ?>
                            </label>
                        </div>
                        <?php endforeach ?>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header py-2"><small class="text-secondary">Layout</small></div>
                <div class="card-body">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="hide_title" id="hide_title" value="1"
                               <?= !empty($page['hide_title']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="hide_title">Überschrift auf der Seite ausblenden</label>
                    </div>
                    <div class="form-text">
                        Titel und Icon werden weiterhin in Menüs, im Browser-Tab und für SEO verwendet —
                        nur die <code>&lt;h1&gt;</code> am Seitenanfang wird nicht angezeigt.
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header py-2"><small class="text-secondary">SEO</small></div>
                <div class="card-body">
                    <label class="form-label" for="meta_description">Meta-Beschreibung</label>
                    <textarea name="meta_description" id="meta_description" class="form-control" rows="3"
                              maxlength="300"><?= htmlspecialchars($page['meta_description'] ?? '') ?></textarea>
                    <div class="form-text">
                        Optional — wird als <code>&lt;meta name="description"&gt;</code> und Open-Graph-Beschreibung
                        verwendet. Leer lassen, um die globale Standard-Beschreibung aus den
                        <a href="/admin/settings">Einstellungen</a> zu nutzen.
                    </div>
                </div>
            </div>

            <?php if (Auth::can('php_upload')): ?>
            <div class="card mb-3">
                <div class="card-header py-2"><small class="text-secondary">Seitentyp</small></div>
                <div class="card-body">
                    <select name="type" id="type" class="form-select">
                        <option value="standard" <?= ($page['type'] ?? 'standard') === 'standard' ? 'selected' : '' ?>>Standard (HTML/Markdown)</option>
                        <option value="php" <?= ($page['type'] ?? '') === 'php' ? 'selected' : '' ?>>PHP / HTML Datei</option>
                    </select>
                </div>
            </div>
            <?php endif ?>

            <?php if ($isEdit): ?>
            <div class="card border-danger">
                <div class="card-header py-2 text-danger"><small>Seite löschen</small></div>
                <div class="card-body">
                    <form method="post" action="/admin/pages/delete/<?= htmlspecialchars($page['slug']) ?>"
                          data-confirm="Seite wirklich löschen?">
                        <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                        <button class="btn btn-outline-danger btn-sm w-100">
                            <i class="bi bi-trash"></i> Seite löschen
                        </button>
                    </form>
                </div>
            </div>
            <?php endif ?>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../partials/icon-picker.php'; ?>
<?php require __DIR__ . '/../partials/media-picker.php'; ?>
<?php require __DIR__ . '/../partials/shortcode-picker.php'; ?>
<?php
$content      = ob_get_clean();
$extraHead    = '<link rel="stylesheet" href="/public/vendor/summernote/summernote-bs5.min.css">
<link rel="stylesheet" href="/public/assets/css/admin-pages-editor.css">';
$extraScriptConfig = array_merge($extraScriptConfig ?? [], ['admin-pages-form-config' => [
    'csrf' => Auth::csrfToken(),
    'slugEdited' => ($isEdit || !empty($page['slug'] ?? '')),
]]);
$extraScriptFiles = array_merge($extraScriptFiles ?? [], [
    '/public/vendor/summernote/jquery.min.js',
    '/public/vendor/summernote/summernote-bs5.min.js',
    '/public/vendor/summernote/summernote-de-DE.min.js',
    '/public/assets/js/admin-pages-form.js',
    '/public/assets/js/media-button.js',
    '/public/assets/js/shortcode-button.js',
    '/public/assets/js/admin-pages-summernote.js',
]);
require dirname(__DIR__) . '/layout.php';
