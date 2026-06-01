<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;

// $editSlug is injected by the route closure when editing; null for create
$editSlug ??= null;
$isEdit   = $editSlug !== null;
$t        = DB::table('pages');
$page     = null;
$errors   = [];

if ($isEdit) {
    $page = DB::fetch("SELECT * FROM `{$t}` WHERE slug = ?", [$editSlug]);
    if (!$page) {
        http_response_code(404);
        echo '404 — Seite nicht gefunden';
        exit;
    }
}

// -- POST handling --

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) {
        $errors[] = 'Ungültige Anfrage.';
    } else {
        $title      = trim($_POST['title']      ?? '');
        $slug       = trim($_POST['slug']       ?? '');
        $content    = $_POST['content']         ?? '';
        $type       = $_POST['type']            ?? 'standard';
        $visibility = $_POST['visibility']      ?? 'public';
        $status     = $_POST['status']          ?? 'draft';

        // Normalize slug
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $slug));
        $slug = trim($slug, '-');

        // Validate
        if (!$title) $errors[] = 'Titel ist Pflichtfeld.';
        if (!$slug)  $errors[] = 'Slug ist Pflichtfeld.';

        if (!in_array($type,       ['standard', 'php'],            true)) $type       = 'standard';
        if (!in_array($visibility, ['public', 'members', 'admin'], true)) $visibility = 'public';
        if (!in_array($status,     ['published', 'draft'],         true)) $status     = 'draft';

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

            if (!in_array($ext, ['php', 'html'], true)) {
                $errors[] = 'Nur .php und .html Dateien erlaubt.';
            } else {
                $dest = ESSE_ROOT . '/pages/' . $slug . '.' . $ext;
                if (!move_uploaded_file($_FILES['php_file']['tmp_name'], $dest)) {
                    $errors[] = 'Datei konnte nicht gespeichert werden.';
                } else {
                    // Delete old file if slug or extension changed
                    if ($isEdit && $page['file_path'] && $page['file_path'] !== $dest) {
                        @unlink(ESSE_ROOT . '/pages/' . $page['file_path']);
                    }
                    $filePath = $slug . '.' . $ext;
                }
            }
        }

        if (empty($errors)) {
            $data = [
                'title'      => $title,
                'slug'       => $slug,
                'content'    => $type === 'standard' ? $content : null,
                'type'       => $type,
                'file_path'  => $type === 'php' ? $filePath : null,
                'visibility' => $visibility,
                'status'     => $status,
                'author_id'  => Auth::id(),
            ];

            if ($isEdit) {
                DB::update($t, $data, ['id' => $page['id']]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Seite gespeichert.'];
            } else {
                DB::insert($t, $data);
                $_SESSION['flash'] = ['type' => 'success', 'message' => "Seite '{$title}' erstellt."];
            }

            header('Location: /admin/pages');
            exit;
        }

        // Re-populate on error
        $page = array_merge($page ?? [], compact('title', 'slug', 'content', 'type', 'visibility', 'status'));
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
                        <input type="text" name="title" id="title" class="form-control"
                               value="<?= htmlspecialchars($page['title'] ?? '') ?>"
                               required autofocus>
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
            <div class="card mt-3" id="php-card" style="display:none">
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
                    <select name="visibility" class="form-select">
                        <?php foreach (['public' => 'Öffentlich', 'members' => 'Nur Mitglieder', 'admin' => 'Nur Admins'] as $val => $label): ?>
                        <option value="<?= $val ?>" <?= ($page['visibility'] ?? 'public') === $val ? 'selected' : '' ?>>
                            <?= $label ?>
                        </option>
                        <?php endforeach ?>
                    </select>
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
                          onsubmit="return confirm('Seite wirklich löschen?')">
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

<script>
// Auto-generate slug from title (only when slug is still empty or unchanged)
const titleEl = document.getElementById('title');
const slugEl  = document.getElementById('slug');
let slugEdited = <?= ($isEdit || !empty($page['slug'] ?? '')) ? 'true' : 'false' ?>;

function slugify(str) {
    const map = {ä:'ae',ö:'oe',ü:'ue',Ä:'ae',Ö:'oe',Ü:'ue',ß:'ss'};
    return str.toLowerCase()
        .replace(/[äöüÄÖÜß]/g, c => map[c] || c)
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

titleEl?.addEventListener('input', () => {
    if (!slugEdited) slugEl.value = slugify(titleEl.value);
});
slugEl?.addEventListener('input', () => {
    slugEdited = slugEl.value.length > 0;
});

// Toggle content/php card based on type
const typeEl      = document.getElementById('type');
const contentCard = document.getElementById('content-card');
const phpCard     = document.getElementById('php-card');

function updateType() {
    if (!typeEl) return;
    if (typeEl.value === 'php') {
        contentCard?.style.setProperty('display', 'none', 'important');
        phpCard?.style.removeProperty('display');
    } else {
        contentCard?.style.removeProperty('display');
        phpCard?.style.setProperty('display', 'none', 'important');
    }
}
typeEl?.addEventListener('change', updateType);
updateType();
</script>
<?php
$content      = ob_get_clean();
$extraHead    = '<link rel="stylesheet" href="/public/vendor/summernote/summernote-bs5.min.css">
<style>
/* Hide original textarea — Summernote replaces it */
#content { display:none; }
.note-editor { border-color:#333 !important; }
.note-toolbar { background:#1e1e1e !important; border-color:#333 !important; }
.note-toolbar .btn { color:#adb5bd; background:transparent; border-color:#333; }
.note-toolbar .btn:hover, .note-toolbar .btn.active { background:#2d2d2d; color:#fff; }
.note-editable { background:#111 !important; color:#e0e0e0 !important; min-height:380px; }
.note-statusbar { background:#1a1a1a !important; border-color:#333 !important; }
.note-placeholder { color:#6c757d !important; }
.dropdown-menu { background:#1e1e1e; border-color:#333; }
.dropdown-item { color:#adb5bd; }
.dropdown-item:hover { background:#2d2d2d; color:#fff; }
.note-modal .modal-content { background:#1a1a1a; }
.note-modal .modal-header, .note-modal .modal-footer { border-color:#333; }
</style>';
$extraScripts = '<script src="/public/vendor/summernote/summernote-bs5.min.js"></script>
<script src="/public/vendor/summernote/summernote-de-DE.min.js"></script>
<script>
(function() {
    $("#content").summernote({
        lang: "de-DE",
        height: 400,
        placeholder: "Seiteninhalt ...",
        toolbar: [
            ["style",   ["style"]],
            ["font",    ["bold","italic","underline","strikethrough","clear"]],
            ["color",   ["color"]],
            ["para",    ["ul","ol","paragraph"]],
            ["table",   ["table"]],
            ["insert",  ["link","picture","hr"]],
            ["view",    ["fullscreen","codeview"]]
        ],
        callbacks: {
            onImageUpload: function(files) {
                const fd = new FormData();
                fd.append("file", files[0]);
                fetch("/admin/files/upload", { method: "POST", body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.url) {
                            $("#content").summernote("insertImage", data.url, files[0].name);
                        } else {
                            alert(data.error || "Upload fehlgeschlagen.");
                        }
                    })
                    .catch(() => alert("Upload fehlgeschlagen."));
            }
        }
    });
})();
</script>';
require dirname(__DIR__) . '/layout.php';
