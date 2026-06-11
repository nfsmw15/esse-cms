<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;

if (!Auth::can('view_logs')) {
    http_response_code(403); echo '403 Forbidden'; exit;
}

$perPage = 50;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$event   = trim((string) ($_GET['event'] ?? ''));
if ($event !== '' && !array_key_exists($event, AuditLog::EVENTS)) {
    $event = '';
}

$result = AuditLog::paginate($page, $perPage, $event ?: null);

$pageTitle = 'Sicherheits-Protokoll';
$activeNav = 'logs';

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <form method="get" class="d-flex gap-2">
        <select name="event" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">Alle Ereignisse</option>
            <?php foreach (AuditLog::EVENTS as $slug => $label): ?>
            <option value="<?= htmlspecialchars($slug) ?>" <?= $event === $slug ? 'selected' : '' ?>>
                <?= htmlspecialchars($label) ?>
            </option>
            <?php endforeach ?>
        </select>
    </form>
    <small class="text-secondary">
        Aufbewahrung: <?= AuditLog::retentionDays() ?> Tage —
        <a href="/admin/settings">Einstellungen</a>
    </small>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr>
                <th>Zeitpunkt</th>
                <th>Ereignis</th>
                <th>Benutzer</th>
                <th>IP-Adresse</th>
                <th>Details</th>
            </tr></thead>
            <tbody>
            <?php if (!$result['rows']): ?>
            <tr><td colspan="5" class="text-secondary text-center py-4">Keine Einträge.</td></tr>
            <?php endif ?>
            <?php foreach ($result['rows'] as $row): ?>
            <tr>
                <td class="text-secondary small"><?= htmlspecialchars($row['created_at']) ?></td>
                <td><?= htmlspecialchars(AuditLog::EVENTS[$row['event']] ?? $row['event']) ?></td>
                <td class="text-secondary small"><?= htmlspecialchars($row['email'] ?? '—') ?></td>
                <td class="text-secondary small"><?= htmlspecialchars($row['ip_address'] ?? '—') ?></td>
                <td class="text-secondary small">
                    <?php if ($row['details']): ?>
                    <?php $details = json_decode((string) $row['details'], true) ?: []; ?>
                    <?php foreach ($details as $k => $v): ?>
                        <?php if (is_array($v)) {
                            $v = array_keys($v) === ['old', 'new']
                                ? ($v['old'] ?? '—') . ' → ' . ($v['new'] ?? '—')
                                : ($v ? implode(', ', $v) : '—');
                        } ?>
                        <span class="badge bg-secondary"><?= htmlspecialchars((string) $k) ?>: <?= htmlspecialchars((string) $v) ?></span>
                    <?php endforeach ?>
                    <?php endif ?>
                </td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($result['pages'] > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <?php for ($p = 1; $p <= $result['pages']; $p++): ?>
        <li class="page-item <?= $p === $result['page'] ? 'active' : '' ?>">
            <a class="page-link" href="?page=<?= $p ?><?= $event !== '' ? '&event=' . urlencode($event) : '' ?>"><?= $p ?></a>
        </li>
        <?php endfor ?>
    </ul>
</nav>
<?php endif ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
