<?php

declare(strict_types=1);

use Esse\DB;
use Esse\Auth;

$t        = DB::table('pages');
$tu       = DB::table('users');
$pages    = (int) DB::value("SELECT COUNT(*) FROM `{$t}` WHERE status = 'published'");
$drafts   = (int) DB::value("SELECT COUNT(*) FROM `{$t}` WHERE status = 'draft'");
$users    = Auth::can('manage_users') ? (int) DB::value("SELECT COUNT(*) FROM `{$tu}`") : null;

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

ob_start();
?>
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card text-center p-3">
            <div style="font-size:2rem;font-weight:700;color:#0d6efd"><?= $pages ?></div>
            <small class="text-secondary">Veröffentlichte Seiten</small>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center p-3">
            <div style="font-size:2rem;font-weight:700;color:#6c757d"><?= $drafts ?></div>
            <small class="text-secondary">Entwürfe</small>
        </div>
    </div>
    <?php if ($users !== null): ?>
    <div class="col-sm-4">
        <div class="card text-center p-3">
            <div style="font-size:2rem;font-weight:700;color:#198754"><?= $users ?></div>
            <small class="text-secondary">Benutzer</small>
        </div>
    </div>
    <?php endif ?>
</div>

<div class="card">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <strong>Zuletzt bearbeitet</strong>
        <?php if (Auth::can('manage_content')): ?>
        <a href="/admin/pages" class="btn btn-sm btn-outline-secondary">Alle Seiten</a>
        <?php endif ?>
    </div>
    <div class="card-body p-0">
        <?php
        $recent = DB::fetchAll(
            "SELECT p.slug, p.title, p.status, p.updated_at, u.display_name
               FROM `{$t}` p
          LEFT JOIN `{$tu}` u ON u.id = p.author_id
           ORDER BY p.updated_at DESC
              LIMIT 8"
        );
        ?>
        <?php if ($recent): ?>
        <table class="table table-sm mb-0">
            <thead><tr>
                <th>Titel</th>
                <th>Slug</th>
                <th>Status</th>
                <th>Autor</th>
                <th>Geändert</th>
            </tr></thead>
            <tbody>
            <?php foreach ($recent as $row): ?>
            <tr>
                <td>
                    <?php if (Auth::can('manage_content')): ?>
                    <a href="/admin/pages/edit/<?= htmlspecialchars($row['slug']) ?>" class="text-decoration-none text-white">
                    <?= htmlspecialchars($row['title']) ?>
                    </a>
                    <?php else: ?>
                    <?= htmlspecialchars($row['title']) ?>
                    <?php endif ?>
                </td>
                <td><code class="text-secondary">/<?= htmlspecialchars($row['slug']) ?></code></td>
                <td>
                    <?php if ($row['status'] === 'published'): ?>
                        <span class="badge bg-success">Live</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Entwurf</span>
                    <?php endif ?>
                </td>
                <td class="text-secondary"><?= htmlspecialchars($row['display_name'] ?? '—') ?></td>
                <td class="text-secondary"><?= date('d.m.Y H:i', strtotime($row['updated_at'])) ?></td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-4 text-secondary text-center">Noch keine Seiten vorhanden.</div>
        <?php endif ?>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
