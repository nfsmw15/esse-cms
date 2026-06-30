<?php

declare(strict_types=1);

use Esse\Auth;
use Esse\AuditLog;
use Esse\DB;
use Esse\Flash;

if (!Auth::can('manage_settings')) {
    http_response_code(403); echo '403 Forbidden'; exit;
}

$ts = DB::table('settings');

// Load all settings into a flat array
$rows     = DB::fetchAll("SELECT `key`, `value` FROM `{$ts}`");
$settings = array_column($rows, 'value', 'key');

$errors = [];

$flash = Flash::consume();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) { http_response_code(403); exit; }

    $save = [
        'site_name'        => trim($_POST['site_name']        ?? ''),
        'site_slogan'      => trim($_POST['site_slogan']      ?? ''),
        'site_url'         => rtrim(trim($_POST['site_url']   ?? ''), '/'),
        'admin_email'           => trim($_POST['admin_email']           ?? ''),
        'registration_enabled'  => isset($_POST['registration_enabled']) ? '1' : '0',
        'registration_requires_approval' => isset($_POST['registration_requires_approval']) ? '1' : '0',
        'mfa_enforcement_level' => in_array($_POST['mfa_enforcement_level'] ?? 'off', ['off', '2fa', 'passkey'], true)
            ? $_POST['mfa_enforcement_level'] : 'off',
        'smtp_host'        => trim($_POST['smtp_host']        ?? ''),
        'smtp_port'        => trim($_POST['smtp_port']        ?? '587'),
        'smtp_user'        => trim($_POST['smtp_user']        ?? ''),
        'smtp_encryption'  => $_POST['smtp_encryption']       ?? 'tls',
        'smtp_from'        => trim($_POST['smtp_from']        ?? ''),
        'smtp_from_name'   => trim($_POST['smtp_from_name']   ?? ''),
        'audit_log_retention_days' => (string) max(1, (int) ($_POST['audit_log_retention_days'] ?? 90)),
        'seo_meta_description' => trim($_POST['seo_meta_description'] ?? ''),
        'seo_sitemap_enabled'  => isset($_POST['seo_sitemap_enabled']) ? '1' : '0',
        'seo_robots_txt'       => trim($_POST['seo_robots_txt'] ?? ''),
    ];
    // Only update if new value entered — store encrypted
    if (!empty($_POST['smtp_pass'])) {
        $save['smtp_pass'] = \Esse\Crypto::encrypt($_POST['smtp_pass']);
    }
    if (!empty($_POST['github_token'])) {
        $save['github_token'] = \Esse\Crypto::encrypt(trim($_POST['github_token']));
    }

    if (!$save['site_name']) $errors[] = 'Seitenname ist Pflichtfeld.';
    if (!filter_var($save['site_url'], FILTER_VALIDATE_URL)) $errors[] = 'Ungültige URL.';

    if (empty($errors)) {
        // Sicherheitsrelevante Änderungen erfassen, bevor die neuen Werte geschrieben werden
        $changes = [];
        foreach (['registration_enabled', 'registration_requires_approval', 'mfa_enforcement_level', 'audit_log_retention_days', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_encryption', 'smtp_from'] as $key) {
            $old = $settings[$key] ?? null;
            if ($old !== $save[$key]) {
                $changes[$key] = ['old' => $old, 'new' => $save[$key]];
            }
        }
        if (isset($save['smtp_pass']))   $changes['smtp_pass']   = 'geändert';
        if (isset($save['github_token'])) $changes['github_token'] = 'geändert';

        foreach ($save as $key => $value) {
            DB::query(
                "INSERT INTO `{$ts}` (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$key, $value]
            );
        }

        if ($changes) {
            AuditLog::record('settings_changed', Auth::id(), Auth::user()['email'] ?? null, $changes);
        }

        Flash::set('success', 'Einstellungen gespeichert.');
        header('Location: /admin/settings');
        exit;
    }
}

$pageTitle = 'Einstellungen';
$activeNav = 'settings';

ob_start();
?>
<?php if ($errors): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach ?>
</div>
<?php endif ?>

<form method="post" action="/admin/settings">
    <input type="hidden" name="_csrf" value="<?= Auth::csrfToken() ?>">
    <div class="row g-4">
        <div class="col-lg-8">

            <div class="card mb-4">
                <div class="card-header py-2"><small class="text-secondary">Allgemein</small></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Seitenname</label>
                        <input type="text" name="site_name" class="form-control"
                               value="<?= htmlspecialchars($settings['site_name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Slogan</label>
                        <input type="text" name="site_slogan" class="form-control"
                               value="<?= htmlspecialchars($settings['site_slogan'] ?? '') ?>">
                        <div class="form-text">Optional — wird unter dem Seitennamen angezeigt (Login, Admin-Bereich). Leer lassen, um nichts anzuzeigen.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">URL</label>
                        <input type="url" name="site_url" class="form-control"
                               value="<?= htmlspecialchars($settings['site_url'] ?? '') ?>" required>
                        <div class="form-text">Ohne abschließenden Slash, z.B. <code>https://example.com</code></div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Admin-E-Mail</label>
                        <input type="email" name="admin_email" class="form-control"
                               value="<?= htmlspecialchars($settings['admin_email'] ?? '') ?>">
                        <div class="form-text">Für Systembenachrichtigungen (z.B. via PHPMailer-Plugin)</div>
                    </div>
                </div>
            </div>

            <!-- SMTP -->
            <div class="card mb-4">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <small class="text-secondary">E-Mail / SMTP</small>
                    <form method="post" action="/admin/settings/test-mail" class="d-inline">
                        <input type="hidden" name="_csrf" value="<?= \Esse\Auth::csrfToken() ?>">
                        <button class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-envelope"></i> Test-Mail senden
                        </button>
                    </form>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-8">
                            <label class="form-label">SMTP-Host</label>
                            <input type="text" name="smtp_host" class="form-control"
                                   placeholder="mail.example.com"
                                   value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Port</label>
                            <input type="number" name="smtp_port" class="form-control"
                                   value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Benutzername</label>
                            <input type="text" name="smtp_user" class="form-control" autocomplete="off"
                                   value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Passwort</label>
                            <input type="password" name="smtp_pass" class="form-control" autocomplete="new-password"
                                   placeholder="<?= empty($settings['smtp_pass']) ? '' : 'Leer lassen, um Passwort beizubehalten' ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Verschlüsselung</label>
                            <select name="smtp_encryption" class="form-select">
                                <option value="tls" <?= ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (Port 587)</option>
                                <option value="ssl" <?= ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL/TLS (Port 465)</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Absender-Adresse</label>
                            <input type="email" name="smtp_from" class="form-control"
                                   value="<?= htmlspecialchars($settings['smtp_from'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Absender-Name</label>
                            <input type="text" name="smtp_from_name" class="form-control"
                                   value="<?= htmlspecialchars($settings['smtp_from_name'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header py-2"><small class="text-secondary">Benutzer</small></div>
                <div class="card-body">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="registration_enabled"
                               value="1" id="reg-enabled"
                               <?= ($settings['registration_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="reg-enabled">
                            Öffentliche Registrierung erlauben
                        </label>
                    </div>
                    <div class="form-text">
                        Wenn aktiviert, können sich Besucher unter <code>/registrieren</code> einen Member-Account erstellen.
                    </div>

                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" name="registration_requires_approval"
                               value="1" id="reg-requires-approval"
                               <?= ($settings['registration_requires_approval'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="reg-requires-approval">
                            Admin-Freigabe für neue Registrierungen erforderlich
                        </label>
                    </div>
                    <div class="form-text">
                        Zusätzlich zur E-Mail-Bestätigung muss ein Admin neue Accounts in <code>/admin/users</code> freigeben, bevor sie sich einloggen können.
                    </div>

                    <label class="form-label mt-3">Starke Authentifizierung erzwingen</label>
                    <select name="mfa_enforcement_level" class="form-select" style="max-width: 20rem">
                        <option value="off"     <?= ($settings['mfa_enforcement_level'] ?? 'off') === 'off'     ? 'selected' : '' ?>>Aus</option>
                        <option value="2fa"     <?= ($settings['mfa_enforcement_level'] ?? 'off') === '2fa'     ? 'selected' : '' ?>>2FA-Pflicht (TOTP oder Passkey)</option>
                        <option value="passkey" <?= ($settings['mfa_enforcement_level'] ?? 'off') === 'passkey' ? 'selected' : '' ?>>Passkey-Pflicht (nur Passkey gilt)</option>
                    </select>
                    <div class="form-text">
                        Ohne eingerichteten zweiten Faktor wird ein Nutzer direkt nach dem Passwort zur Einrichtung gezwungen, bevor er die Seite nutzen kann.
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header py-2"><small class="text-secondary">Sicherheits-Protokoll</small></div>
                <div class="card-body">
                    <label class="form-label">Aufbewahrungsdauer (Tage)</label>
                    <input type="number" name="audit_log_retention_days" class="form-control" min="1" style="max-width: 10rem"
                           value="<?= htmlspecialchars($settings['audit_log_retention_days'] ?? '90') ?>">
                    <div class="form-text">
                        Sicherheitsereignisse (Logins, Passwort-Resets, 2FA-/Passkey-Änderungen, Benutzerverwaltung) werden
                        unter <a href="/admin/logs">Protokolle</a> angezeigt und nach Ablauf dieser Frist automatisch gelöscht.
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header py-2"><small class="text-secondary">SEO</small></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Standard-Meta-Beschreibung</label>
                        <textarea name="seo_meta_description" class="form-control" rows="3" maxlength="300"
                                  ><?= htmlspecialchars($settings['seo_meta_description'] ?? '') ?></textarea>
                        <div class="form-text">
                            Wird als <code>&lt;meta name="description"&gt;</code> und Open-Graph-Beschreibung verwendet,
                            wenn eine Seite keine eigene Meta-Beschreibung hat.
                        </div>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="seo_sitemap_enabled"
                               value="1" id="seo-sitemap-enabled"
                               <?= ($settings['seo_sitemap_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="seo-sitemap-enabled">
                            <code>/sitemap.xml</code> bereitstellen
                        </label>
                        <div class="form-text">
                            Erzeugt automatisch eine Sitemap mit allen veröffentlichten Seiten.
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label"><code>/robots.txt</code></label>
                        <textarea name="seo_robots_txt" class="form-control font-monospace" rows="4"
                                  placeholder="User-agent: *&#10;Allow: /"
                                  ><?= htmlspecialchars($settings['seo_robots_txt'] ?? '') ?></textarea>
                        <div class="form-text">
                            Optional — eigener Inhalt für <code>/robots.txt</code>. Leer lassen für die Standardregeln
                            (<code>User-agent: *</code> / <code>Allow: /</code><?= ' ' ?>+ Sitemap-Verweis, falls aktiviert).
                        </div>
                    </div>
                </div>
            </div>

            <!-- GitHub API Token -->
            <div class="card mb-4">
                <div class="card-header py-2"><small class="text-secondary">GitHub API</small></div>
                <div class="card-body">
                    <label class="form-label">Personal Access Token
                        <small class="text-secondary">(optional — erhöht Rate-Limit von 60 auf 5000 req/h)</small>
                    </label>
                    <input type="password" name="github_token" class="form-control font-monospace"
                           autocomplete="new-password"
                           placeholder="<?= empty($settings['github_token']) ? 'Token eingeben...' : 'Leer lassen, um Token beizubehalten' ?>">
                    <div class="form-text">
                        Fine-Grained PAT ohne Berechtigungen —
                        <a href="https://github.com/settings/tokens?type=beta" target="_blank" rel="noopener">
                            GitHub → Settings → Developer Settings → Fine-grained tokens
                        </a>.
                        Wird verschlüsselt gespeichert.
                        <?php if (!empty($settings['github_token'])): ?>
                        <span class="text-success ms-1"><i class="bi bi-check-circle"></i> Token gespeichert</span>
                        <?php endif ?>
                    </div>
                </div>
            </div>

            <button class="btn btn-primary">
                <i class="bi bi-floppy"></i> Speichern
            </button>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header py-2"><small class="text-secondary">System-Info</small></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td class="text-secondary">ESSE CMS</td>
                            <td><?= ESSE_VERSION ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">PHP</td>
                            <td><?= PHP_VERSION ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Server</td>
                            <td><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? '—') ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</form>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
