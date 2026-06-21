<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\DB;
use Esse\Flash;
use Esse\UserFields;

if (!Auth::can('manage_settings')) {
    http_response_code(403); echo '403 Forbidden'; exit;
}

$t = DB::table('user_fields');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $action = $_POST['_action'] ?? 'save';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        DB::query("DELETE FROM `{$t}` WHERE id = ?", [$id]);
        Flash::set('success', 'Feld gelöscht.');
        header('Location: /admin/user-fields');
        exit;
    }

    if (in_array($action, ['move_up', 'move_down'], true)) {
        $id  = (int) ($_POST['id'] ?? 0);
        $all = DB::fetchAll("SELECT id, sort_order FROM `{$t}` ORDER BY sort_order ASC, id ASC");
        $idx = null;
        foreach ($all as $i => $row) {
            if ((int) $row['id'] === $id) { $idx = $i; break; }
        }
        $swapWith = $action === 'move_up' ? $idx - 1 : $idx + 1;
        if ($idx !== null && isset($all[$swapWith])) {
            DB::update($t, ['sort_order' => $swapWith], ['id' => $all[$idx]['id']]);
            DB::update($t, ['sort_order' => $idx], ['id' => $all[$swapWith]['id']]);
        }
        header('Location: /admin/user-fields');
        exit;
    }

    if ($action === 'save') {
        $id       = (int) ($_POST['id'] ?? 0);
        $label    = trim($_POST['label'] ?? '');
        $type     = $_POST['type']       ?? 'text';
        $options  = trim($_POST['options'] ?? '');
        $required = isset($_POST['required']) ? 1 : 0;
        $onReg    = isset($_POST['show_on_register']) ? 1 : 0;
        $onProf   = isset($_POST['show_on_profile'])  ? 1 : 0;

        if (!in_array($type, array_keys(UserFields::TYPES), true)) $type = 'text';
        if (!$label) $errors[] = 'Bezeichnung ist Pflichtfeld.';

        if (empty($errors)) {
            $data = [
                'label'            => $label,
                'type'             => $type,
                'options'          => $type === 'select' ? $options : null,
                'required'         => $required,
                'show_on_register' => $onReg,
                'show_on_profile'  => $onProf,
            ];

            if ($id > 0) {
                DB::update($t, $data, ['id' => $id]);
                Flash::set('success', 'Feld gespeichert.');
            } else {
                $key = strtolower(preg_replace('/[^a-z0-9]+/', '_', $label));
                $key = trim($key, '_');
                $base = $key;
                $i = 1;
                while (DB::fetch("SELECT id FROM `{$t}` WHERE field_key = ?", [$key])) {
                    $key = $base . '_' . (++$i);
                }
                $maxOrder = (int) (DB::value("SELECT MAX(sort_order) FROM `{$t}`") ?? 0);
                $data['field_key']  = $key;
                $data['sort_order'] = $maxOrder + 1;
                DB::insert($t, $data);
                Flash::set('success', "Feld '{$label}' erstellt.");
            }

            header('Location: /admin/user-fields');
            exit;
        }
    }
}

$fields = UserFields::all();

$editId    = (int) ($_GET['edit'] ?? 0);
$editField = $editId ? DB::fetch("SELECT * FROM `{$t}` WHERE id = ?", [$editId]) : null;
$isNew     = isset($_GET['new']);
$showForm  = $isNew || $editField !== null;

$flash = Flash::consume();

$pageTitle = 'Profilfelder';
$activeNav = 'user-fields';

ob_start();
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['message']) ?></div>
<?php endif ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach ?>
</div>
<?php endif ?>

<p class="text-secondary">
    Zusätzliche Felder für Registrierung, Profil und Benutzerverwaltung. Pflichtfelder müssen
    von Benutzern ausgefüllt werden, bevor das jeweilige Formular gespeichert werden kann.
</p>

<?php if (!$showForm): ?>
<div class="d-flex justify-content-end mb-3">
    <a href="/admin/user-fields?new=1" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> Neues Feld
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm mb-0 align-middle">
            <thead>
                <tr>
                    <th>Bezeichnung</th>
                    <th>Schlüssel</th>
                    <th>Typ</th>
                    <th>Pflicht</th>
                    <th>Registrierung</th>
                    <th>Profil</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($fields): ?>
                <?php foreach ($fields as $i => $f): ?>
                <tr>
                    <td><?= htmlspecialchars($f['label']) ?></td>
                    <td><code class="text-secondary small"><?= htmlspecialchars($f['field_key']) ?></code></td>
                    <td><?= htmlspecialchars(UserFields::TYPES[$f['type']] ?? $f['type']) ?></td>
                    <td><?= (int) $f['required'] === 1 ? '<i class="bi bi-check-lg text-success"></i>' : '<span class="text-secondary">—</span>' ?></td>
                    <td><?= (int) $f['show_on_register'] === 1 ? '<i class="bi bi-check-lg text-success"></i>' : '<span class="text-secondary">—</span>' ?></td>
                    <td><?= (int) $f['show_on_profile'] === 1 ? '<i class="bi bi-check-lg text-success"></i>' : '<span class="text-secondary">—</span>' ?></td>
                    <td class="text-end">
                        <form method="post" class="d-inline">
                            <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                            <button type="submit" name="_action" value="move_up" class="btn btn-sm btn-outline-secondary" <?= $i === 0 ? 'disabled' : '' ?>>
                                <i class="bi bi-arrow-up"></i>
                            </button>
                            <button type="submit" name="_action" value="move_down" class="btn btn-sm btn-outline-secondary" <?= $i === count($fields) - 1 ? 'disabled' : '' ?>>
                                <i class="bi bi-arrow-down"></i>
                            </button>
                        </form>
                        <a href="/admin/user-fields?edit=<?= (int) $f['id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="post" class="d-inline" data-confirm="Feld '<?= htmlspecialchars($f['label'], ENT_QUOTES, 'UTF-8') ?>' wirklich löschen? Bereits gespeicherte Werte gehen verloren.">
                            <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
                            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                            <button type="submit" name="_action" value="delete" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach ?>
                <?php else: ?>
                <tr>
                    <td colspan="7" class="text-center text-secondary py-3">
                        Keine Profilfelder definiert.
                        <a href="/admin/user-fields?new=1" class="text-primary">Erstes Feld erstellen</a>
                    </td>
                </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>

<div class="row">
    <div class="col-lg-6">
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="id" value="<?= (int) ($editField['id'] ?? 0) ?>">

            <div class="card mb-3">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Bezeichnung</label>
                        <input type="text" name="label" class="form-control"
                               value="<?= htmlspecialchars($editField['label'] ?? '') ?>" required autofocus>
                        <?php if ($editField): ?>
                        <div class="form-text">Schlüssel: <code><?= htmlspecialchars($editField['field_key']) ?></code></div>
                        <?php endif ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Typ</label>
                        <select name="type" id="field-type" class="form-select">
                            <?php foreach (UserFields::TYPES as $val => $lbl): ?>
                            <option value="<?= $val ?>" <?= ($editField['type'] ?? 'text') === $val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($lbl) ?>
                            </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="mb-3" id="field-options" <?= ($editField['type'] ?? 'text') !== 'select' ? 'style="display:none"' : '' ?>>
                        <label class="form-label">Optionen</label>
                        <textarea name="options" class="form-control font-monospace" rows="4"
                                  placeholder="Eine Option pro Zeile"><?= htmlspecialchars($editField['options'] ?? '') ?></textarea>
                        <div class="form-text">Eine Auswahlmöglichkeit pro Zeile — nur für Typ "Auswahl".</div>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="required" value="1" id="ff-required"
                               <?= (int) ($editField['required'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ff-required">Pflichtfeld</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="show_on_register" value="1" id="ff-register"
                               <?= (int) ($editField['show_on_register'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ff-register">In Registrierung anzeigen</label>
                    </div>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="show_on_profile" value="1" id="ff-profile"
                               <?= (int) ($editField['show_on_profile'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ff-profile">Im Profil bearbeitbar</label>
                    </div>
                </div>
            </div>

            <button class="btn btn-primary">
                <i class="bi bi-floppy"></i> Speichern
            </button>
            <a href="/admin/user-fields" class="btn btn-outline-secondary">Abbrechen</a>
        </form>
    </div>
</div>

<script>
document.getElementById('field-type').addEventListener('change', function () {
    document.getElementById('field-options').style.display = this.value === 'select' ? '' : 'none';
});
</script>

<?php endif ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';