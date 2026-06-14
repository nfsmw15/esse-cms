<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\Media;

if (!Auth::canAny(['manage_files', 'manage_content'])) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

// Extensions allowed for direct uploads via the Mediathek (broader than the editor's image-only upload)
$allowedExt = [
    'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image',
    'pdf' => 'document', 'doc' => 'document', 'docx' => 'document', 'odt' => 'document',
    'txt' => 'document', 'zip' => 'file',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $action = $_POST['_action'] ?? '';

    // AJAX: check where a file is referenced before deleting
    if ($action === 'usages') {
        header('Content-Type: application/json');
        $id = (int) ($_POST['id'] ?? 0);
        $media = Media::find($id);
        if (!$media) { echo json_encode(['error' => 'not_found']); exit; }
        echo json_encode(['pages' => Media::usages($media['path'])]);
        exit;
    }

    if ($action === 'upload' && !empty($_FILES['file']['tmp_name'])) {
        $file = $_FILES['file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Upload fehlgeschlagen.'];
        } elseif (!isset($allowedExt[$ext])) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dateityp nicht erlaubt. Erlaubt: ' . implode(', ', array_keys($allowedExt))];
        } elseif ($file['size'] > 10 * 1024 * 1024) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Datei zu groß (max. 10 MB).'];
        } else {
            $mime = mime_content_type($file['tmp_name']) ?: '';

            if ($allowedExt[$ext] === 'image' && (!str_starts_with($mime, 'image/') || @getimagesize($file['tmp_name']) === false)) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Ungültige Bilddatei.'];
            } else {
                $uploadDir = ESSE_ROOT . '/public/uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $baseName = pathinfo($file['name'], PATHINFO_FILENAME);
                $baseName = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $baseName);
                $baseName = trim($baseName, '-') ?: 'file';
                $fileName = $baseName . '_' . uniqid() . '.' . $ext;
                $dest     = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $visibility = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
                    Media::register('/public/uploads/' . $fileName, [
                        'filename'    => $file['name'],
                        'mime_type'   => $mime,
                        'type'        => $allowedExt[$ext],
                        'size'        => $file['size'],
                        'visibility'  => $visibility,
                        'uploaded_by' => Auth::id(),
                        'source'      => 'media',
                    ]);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Datei hochgeladen.'];
                } else {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Speichern fehlgeschlagen.'];
                }
            }
        }
        header('Location: /admin/media');
        exit;
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        Media::update($id, [
            'alt_text'    => trim($_POST['alt_text'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'visibility'  => ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public',
        ]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Datei aktualisiert.'];
        header('Location: /admin/media');
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        Media::delete($id);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Datei gelöscht.'];
        header('Location: /admin/media');
        exit;
    }

    if ($action === 'import') {
        $n = Media::scanUploads();
        $_SESSION['flash'] = ['type' => 'success', 'message' => "{$n} Datei(en) importiert."];
        header('Location: /admin/media');
        exit;
    }

    http_response_code(400);
    exit;
}

$filters = [
    'type'       => $_GET['type'] ?? '',
    'visibility' => $_GET['visibility'] ?? '',
    'q'          => trim($_GET['q'] ?? ''),
];

$items = Media::list(array_filter($filters));

$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

function mediaThumb(array $item): string
{
    $path = htmlspecialchars($item['path']);
    if ($item['type'] === 'image') {
        return "<img src=\"{$path}\" class=\"media-thumb\" loading=\"lazy\" alt=\"\">";
    }
    $icon = $item['type'] === 'document' ? 'file-earmark-text' : 'file-earmark';
    return "<div class=\"media-thumb media-thumb-file\"><i class=\"bi bi-{$icon}\"></i></div>";
}

function formatSize(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

$pageTitle = 'Mediathek';
$activeNav = 'media';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <form method="get" class="d-flex gap-2 flex-wrap">
        <input type="text" name="q" class="form-control form-control-sm media-filter-q" placeholder="Suche..."
               value="<?= htmlspecialchars($filters['q']) ?>">
        <select name="type" class="form-select form-select-sm media-filter-select media-filter-autosubmit">
            <option value="">Alle Typen</option>
            <?php foreach (Media::TYPES as $val => $label): ?>
            <option value="<?= $val ?>" <?= $filters['type'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach ?>
        </select>
        <select name="visibility" class="form-select form-select-sm media-filter-select media-filter-autosubmit">
            <option value="">Alle Sichtbarkeiten</option>
            <option value="public" <?= $filters['visibility'] === 'public' ? 'selected' : '' ?>>Öffentlich</option>
            <option value="private" <?= $filters['visibility'] === 'private' ? 'selected' : '' ?>>Privat</option>
        </select>
        <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
    </form>
    <div class="d-flex gap-2">
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="_action" value="import">
            <button class="btn btn-sm btn-outline-secondary" title="Vorhandene Dateien in /public/uploads importieren">
                <i class="bi bi-arrow-repeat"></i> Bestand importieren
            </button>
        </form>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadModal">
            <i class="bi bi-upload"></i> Datei hochladen
        </button>
    </div>
</div>

<?php if ($items): ?>
<div class="media-grid">
    <?php foreach ($items as $item): ?>
    <div class="media-card">
        <div class="media-card-thumb">
            <?= mediaThumb($item) ?>
            <?php if ($item['visibility'] === 'private'): ?>
            <span class="badge bg-secondary media-private-badge"><i class="bi bi-lock-fill"></i> Privat</span>
            <?php endif ?>
        </div>
        <div class="media-card-body">
            <div class="media-card-name" title="<?= htmlspecialchars($item['filename']) ?>"><?= htmlspecialchars($item['filename']) ?></div>
            <div class="media-card-meta text-secondary small"><?= formatSize((int) $item['size']) ?></div>
        </div>
        <div class="media-card-actions">
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-bs-toggle="modal" data-bs-target="#editMediaModal"
                    data-id="<?= $item['id'] ?>"
                    data-alt="<?= htmlspecialchars($item['alt_text'], ENT_QUOTES) ?>"
                    data-description="<?= htmlspecialchars($item['description'], ENT_QUOTES) ?>"
                    data-visibility="<?= htmlspecialchars($item['visibility']) ?>"
                    data-path="<?= htmlspecialchars($item['path'], ENT_QUOTES) ?>">
                <i class="bi bi-pencil"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger media-delete-btn"
                    data-id="<?= $item['id'] ?>"
                    data-name="<?= htmlspecialchars($item['filename'], ENT_QUOTES) ?>">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </div>
    <?php endforeach ?>
</div>
<?php else: ?>
<div class="text-center text-secondary py-5">
    Keine Dateien gefunden.
</div>
<?php endif ?>

<!-- ── Upload-Modal ─────────────────────────────────────────────────────────── -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark border-secondary">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                <input type="hidden" name="_action" value="upload">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Datei hochladen</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <input type="file" name="file" class="form-control" required>
                        <div class="form-text">Erlaubt: <?= implode(', ', array_keys($allowedExt)) ?> (max. 10 MB)</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sichtbarkeit</label>
                        <select name="visibility" class="form-select">
                            <option value="public">Öffentlich</option>
                            <option value="private">Privat</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button class="btn btn-primary">Hochladen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Bearbeiten-Modal ─────────────────────────────────────────────────────── -->
<div class="modal fade" id="editMediaModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark border-secondary">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                <input type="hidden" name="_action" value="update">
                <input type="hidden" name="id" id="em-id">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Datei bearbeiten</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <code class="text-secondary small" id="em-path"></code>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Alt-Text</label>
                        <input type="text" name="alt_text" id="em-alt" class="form-control" maxlength="255">
                        <div class="form-text">Wird beim Einfügen über die Mediathek als Bildbeschreibung übernommen.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Beschreibung</label>
                        <textarea name="description" id="em-description" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sichtbarkeit</label>
                        <select name="visibility" id="em-visibility" class="form-select">
                            <option value="public">Öffentlich</option>
                            <option value="private">Privat</option>
                        </select>
                        <div class="form-text">Private Dateien sind über die öffentliche URL nicht erreichbar und werden in der Mediathek markiert.</div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Löschen-Modal ────────────────────────────────────────────────────────── -->
<div class="modal fade" id="deleteMediaModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content bg-dark border-secondary">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="id" id="dm-id">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Datei löschen</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>„<span id="dm-name"></span>“ wirklich löschen?</p>
                    <div id="dm-usages"></div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button class="btn btn-danger">Löschen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();

$extraScriptConfig = array_merge($extraScriptConfig ?? [], ['admin-media-config' => [
    'csrf' => Auth::csrfToken(),
]]);
$extraScriptFiles = array_merge($extraScriptFiles ?? [], ['/public/assets/js/admin-media.js']);

require __DIR__ . '/layout.php';
