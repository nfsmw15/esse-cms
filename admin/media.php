<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;
use Esse\Flash;
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

// Aktuell angezeigter Ordner (Root = null)
$currentFolderId = ($_GET['folder_id'] ?? '') !== '' ? (int) $_GET['folder_id'] : null;
if ($currentFolderId !== null && !Media::findFolder($currentFolderId)) {
    $currentFolderId = null;
}
$folderQuery = $currentFolderId !== null ? '?folder_id=' . $currentFolderId : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $action = $_POST['_action'] ?? '';

    // Ordner, zu dem nach der Aktion zurueck-navigiert werden soll
    $returnFolderId = ($_POST['return_folder_id'] ?? '') !== '' ? (int) $_POST['return_folder_id'] : null;
    $returnQuery    = $returnFolderId !== null ? '?folder_id=' . $returnFolderId : '';

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
            AuditLog::record('file_upload_rejected', Auth::id(), Auth::user()['email'] ?? null, ['reason' => 'upload_error', 'filename' => $file['name'] ?? null]);
            Flash::set('danger', 'Upload fehlgeschlagen.');
        } elseif (!isset($allowedExt[$ext])) {
            AuditLog::record('file_upload_rejected', Auth::id(), Auth::user()['email'] ?? null, ['reason' => 'extension', 'filename' => $file['name'], 'extension' => $ext]);
            Flash::set('danger', 'Dateityp nicht erlaubt. Erlaubt: ' . implode(', ', array_keys($allowedExt)));
        } elseif ($file['size'] > 10 * 1024 * 1024) {
            AuditLog::record('file_upload_rejected', Auth::id(), Auth::user()['email'] ?? null, ['reason' => 'size', 'filename' => $file['name'], 'size' => $file['size']]);
            Flash::set('danger', 'Datei zu groß (max. 10 MB).');
        } else {
            $mime = mime_content_type($file['tmp_name']) ?: '';

            if ($allowedExt[$ext] === 'image' && (!str_starts_with($mime, 'image/') || @getimagesize($file['tmp_name']) === false)) {
                AuditLog::record('file_upload_rejected', Auth::id(), Auth::user()['email'] ?? null, ['reason' => 'image_invalid', 'filename' => $file['name'], 'mime_type' => $mime]);
                Flash::set('danger', 'Ungültige Bilddatei.');
            } else {
                $visibility = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';

                // Private Dateien landen direkt im geschützten Speicherort außerhalb des
                // Webroots, nicht unter /public/uploads — sonst wären sie trotz "Privat"-Markierung
                // sofort per direkter URL erreichbar.
                if ($visibility === 'private') {
                    $uploadDir    = ESSE_PRIVATE_PATH . '/storage/uploads/';
                    $registerPath = '/private-media/';
                } else {
                    $uploadDir    = ESSE_ROOT . '/public/uploads/';
                    $registerPath = '/public/uploads/';
                }
                if (!is_dir($uploadDir)) mkdir($uploadDir, $visibility === 'private' ? 0750 : 0755, true);

                $baseName = pathinfo($file['name'], PATHINFO_FILENAME);
                $baseName = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $baseName);
                $baseName = trim($baseName, '-') ?: 'file';
                $fileName = $baseName . '_' . uniqid() . '.' . $ext;
                $dest     = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $uploadFolderId = ($_POST['folder_id'] ?? '') !== '' ? (int) $_POST['folder_id'] : null;
                    $mediaId = Media::register($registerPath . $fileName, [
                        'filename'    => $file['name'],
                        'mime_type'   => $mime,
                        'type'        => $allowedExt[$ext],
                        'size'        => $file['size'],
                        'visibility'  => $visibility,
                        'uploaded_by' => Auth::id(),
                        'source'      => 'media',
                        'folder_id'   => $uploadFolderId,
                    ]);
                    AuditLog::record('media_uploaded', Auth::id(), Auth::user()['email'] ?? null, [
                        'media_id'   => $mediaId,
                        'path'       => $registerPath . $fileName,
                        'filename'   => $file['name'],
                        'mime_type'  => $mime,
                        'size'       => $file['size'],
                        'visibility' => $visibility,
                    ]);
                    Flash::set('success', 'Datei hochgeladen.');
                } else {
                    Flash::set('danger', 'Speichern fehlgeschlagen.');
                }
            }
        }
        header('Location: /admin/media' . $returnQuery);
        exit;
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $folderId = ($_POST['folder_id'] ?? '') !== '' ? (int) $_POST['folder_id'] : null;
        $newVisibility = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';

        $before = Media::find($id);

        // Sichtbarkeitswechsel verschiebt die physische Datei (öffentlicher Webroot <-> geschützter
        // Speicherort) — eine reine DB-Spaltenänderung würde die Datei am alten, falschen Ort lassen.
        if ($before && $before['visibility'] !== $newVisibility) {
            if (!Media::setVisibility($id, $newVisibility)) {
                Flash::set('danger', 'Sichtbarkeit konnte nicht geändert werden — Datei nicht gefunden.');
                header('Location: /admin/media' . $returnQuery);
                exit;
            }
            AuditLog::record('media_visibility_changed', Auth::id(), Auth::user()['email'] ?? null, [
                'media_id' => $id,
                'path'     => $before['path'],
                'filename' => $before['filename'],
                'old'      => $before['visibility'],
                'new'      => $newVisibility,
            ]);
        }

        Media::update($id, [
            'alt_text'    => trim($_POST['alt_text'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'folder_id'   => $folderId,
        ]);

        Flash::set('success', 'Datei aktualisiert.');
        header('Location: /admin/media' . $returnQuery);
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        Media::delete($id);
        Flash::set('success', 'Datei gelöscht.');
        header('Location: /admin/media' . $returnQuery);
        exit;
    }

    if ($action === 'import') {
        $n = Media::scanUploads();
        Flash::set('success', "{$n} Datei(en) importiert.");
        header('Location: /admin/media' . $returnQuery);
        exit;
    }

    if ($action === 'create_folder') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            Flash::set('danger', 'Ordnername darf nicht leer sein.');
        } else {
            Media::createFolder($name, $returnFolderId);
            Flash::set('success', 'Ordner erstellt.');
        }
        header('Location: /admin/media' . $returnQuery);
        exit;
    }

    if ($action === 'rename_folder') {
        $id   = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($name !== '' && Media::findFolder($id)) {
            Media::renameFolder($id, $name);
            Flash::set('success', 'Ordner umbenannt.');
        } else {
            Flash::set('danger', 'Ordner konnte nicht umbenannt werden.');
        }
        header('Location: /admin/media' . $returnQuery);
        exit;
    }

    if ($action === 'delete_folder') {
        $id = (int) ($_POST['id'] ?? 0);
        if (Media::deleteFolder($id)) {
            Flash::set('success', 'Ordner gelöscht.');
        } else {
            Flash::set('danger', 'Ordner ist nicht leer und kann nicht gelöscht werden.');
        }
        header('Location: /admin/media' . $returnQuery);
        exit;
    }

    http_response_code(400);
    exit;
}

$filters = array_filter([
    'type'       => $_GET['type'] ?? '',
    'visibility' => $_GET['visibility'] ?? '',
    'q'          => trim($_GET['q'] ?? ''),
]);
$filters['folder_id'] = $currentFolderId;

$items      = Media::list($filters);
$subFolders = Media::listFolders($currentFolderId);
$breadcrumb = Media::folderPath($currentFolderId);
$allFolders = Media::allFolders();

$flash = Flash::consume();

function mediaThumb(array $item): string
{
    // Private Dateien liegen ausserhalb des Webroots — ueber den kontrollierten,
    // berechtigungsgeprueften Endpoint statt des (fuer private Dateien nicht mehr aufloesbaren) Pfads.
    $src  = $item['visibility'] === 'private' ? '/admin/media/file/' . $item['id'] : $item['path'];
    $path = htmlspecialchars($src);
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

<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb media-breadcrumb">
        <li class="breadcrumb-item<?= $currentFolderId === null ? ' active' : '' ?>">
            <?php if ($currentFolderId === null): ?>
                <i class="bi bi-folder2-open"></i> Mediathek
            <?php else: ?>
                <a href="/admin/media"><i class="bi bi-folder2-open"></i> Mediathek</a>
            <?php endif ?>
        </li>
        <?php foreach ($breadcrumb as $i => $crumb): ?>
        <li class="breadcrumb-item<?= $i === count($breadcrumb) - 1 ? ' active' : '' ?>">
            <?php if ($i === count($breadcrumb) - 1): ?>
                <?= htmlspecialchars($crumb['name']) ?>
            <?php else: ?>
                <a href="/admin/media?folder_id=<?= $crumb['id'] ?>"><?= htmlspecialchars($crumb['name']) ?></a>
            <?php endif ?>
        </li>
        <?php endforeach ?>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <form method="get" class="d-flex gap-2 flex-wrap">
        <?php if ($currentFolderId !== null): ?>
        <input type="hidden" name="folder_id" value="<?= $currentFolderId ?>">
        <?php endif ?>
        <input type="text" name="q" class="form-control form-control-sm media-filter-q" placeholder="Suche..."
               value="<?= htmlspecialchars($filters['q'] ?? '') ?>">
        <select name="type" class="form-select form-select-sm media-filter-select media-filter-autosubmit">
            <option value="">Alle Typen</option>
            <?php foreach (Media::TYPES as $val => $label): ?>
            <option value="<?= $val ?>" <?= ($filters['type'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach ?>
        </select>
        <select name="visibility" class="form-select form-select-sm media-filter-select media-filter-autosubmit">
            <option value="">Alle Sichtbarkeiten</option>
            <option value="public" <?= ($filters['visibility'] ?? '') === 'public' ? 'selected' : '' ?>>Öffentlich</option>
            <option value="private" <?= ($filters['visibility'] ?? '') === 'private' ? 'selected' : '' ?>>Privat</option>
        </select>
        <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
    </form>
    <div class="d-flex gap-2">
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="_action" value="import">
            <input type="hidden" name="return_folder_id" value="<?= $currentFolderId ?? '' ?>">
            <button class="btn btn-sm btn-outline-secondary" title="Vorhandene Dateien in /public/uploads importieren">
                <i class="bi bi-arrow-repeat"></i> Bestand importieren
            </button>
        </form>
        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newFolderModal">
            <i class="bi bi-folder-plus"></i> Neuer Ordner
        </button>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadModal">
            <i class="bi bi-upload"></i> Datei hochladen
        </button>
    </div>
</div>

<?php if ($subFolders || $items): ?>
<div class="media-grid">
    <?php foreach ($subFolders as $folder): ?>
    <div class="media-card media-folder-card">
        <a href="/admin/media?folder_id=<?= $folder['id'] ?>" class="media-folder-link">
            <div class="media-card-thumb">
                <div class="media-thumb media-thumb-folder"><i class="bi bi-folder-fill"></i></div>
            </div>
            <div class="media-card-body">
                <div class="media-card-name" title="<?= htmlspecialchars($folder['name']) ?>"><?= htmlspecialchars($folder['name']) ?></div>
            </div>
        </a>
        <div class="media-card-actions">
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-bs-toggle="modal" data-bs-target="#renameFolderModal"
                    data-id="<?= $folder['id'] ?>"
                    data-name="<?= htmlspecialchars($folder['name'], ENT_QUOTES) ?>">
                <i class="bi bi-pencil"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger"
                    data-bs-toggle="modal" data-bs-target="#deleteFolderModal"
                    data-id="<?= $folder['id'] ?>"
                    data-name="<?= htmlspecialchars($folder['name'], ENT_QUOTES) ?>">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </div>
    <?php endforeach ?>
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
            <div class="media-card-meta text-secondary small">
                <?= formatSize((int) $item['size']) ?>
                · <span class="badge bg-secondary media-source-badge"><?= htmlspecialchars(Media::sourceLabel($item['source'])) ?></span>
            </div>
        </div>
        <div class="media-card-actions">
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-bs-toggle="modal" data-bs-target="#editMediaModal"
                    data-id="<?= $item['id'] ?>"
                    data-alt="<?= htmlspecialchars($item['alt_text'], ENT_QUOTES) ?>"
                    data-description="<?= htmlspecialchars($item['description'], ENT_QUOTES) ?>"
                    data-visibility="<?= htmlspecialchars($item['visibility']) ?>"
                    data-folder-id="<?= $item['folder_id'] !== null ? $item['folder_id'] : '' ?>"
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
    Dieser Ordner ist leer.
</div>
<?php endif ?>

<!-- ── Upload-Modal ─────────────────────────────────────────────────────────── -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark border-secondary">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                <input type="hidden" name="_action" value="upload">
                <input type="hidden" name="folder_id" value="<?= $currentFolderId ?? '' ?>">
                <input type="hidden" name="return_folder_id" value="<?= $currentFolderId ?? '' ?>">
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
                <input type="hidden" name="return_folder_id" value="<?= $currentFolderId ?? '' ?>">
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
                    <div class="mb-3">
                        <label class="form-label">Ordner</label>
                        <select name="folder_id" id="em-folder" class="form-select">
                            <option value="">— Wurzelebene —</option>
                            <?php foreach ($allFolders as $f): ?>
                            <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
                            <?php endforeach ?>
                        </select>
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
                <input type="hidden" name="return_folder_id" value="<?= $currentFolderId ?? '' ?>">
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

<!-- ── Neuer-Ordner-Modal ───────────────────────────────────────────────────── -->
<div class="modal fade" id="newFolderModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content bg-dark border-secondary">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                <input type="hidden" name="_action" value="create_folder">
                <input type="hidden" name="return_folder_id" value="<?= $currentFolderId ?? '' ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Neuer Ordner</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" name="name" class="form-control" maxlength="255" required autofocus placeholder="Ordnername">
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button class="btn btn-primary">Erstellen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Ordner-umbenennen-Modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="renameFolderModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content bg-dark border-secondary">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                <input type="hidden" name="_action" value="rename_folder">
                <input type="hidden" name="id" id="rf-id">
                <input type="hidden" name="return_folder_id" value="<?= $currentFolderId ?? '' ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Ordner umbenennen</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" name="name" id="rf-name" class="form-control" maxlength="255" required>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Ordner-löschen-Modal ─────────────────────────────────────────────────── -->
<div class="modal fade" id="deleteFolderModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content bg-dark border-secondary">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                <input type="hidden" name="_action" value="delete_folder">
                <input type="hidden" name="id" id="df-id">
                <input type="hidden" name="return_folder_id" value="<?= $currentFolderId ?? '' ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Ordner löschen</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Ordner „<span id="df-name"></span>“ wirklich löschen?</p>
                    <p class="text-secondary small mb-0">Nur möglich, wenn der Ordner leer ist.</p>
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
