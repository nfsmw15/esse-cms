<?php
/**
 * Content partial for /admin/reset-password (auth.reset_password.render hook).
 *
 * @var array $data
 */
?>
<div class="row justify-content-center">
    <div class="col-lg-5">
        <?php if (!empty($data['success'])): ?>
        <div class="alert alert-success">
            Passwort erfolgreich geändert. Du kannst dich jetzt anmelden.
        </div>
        <a href="/login" class="btn btn-primary w-100">Zum Login</a>

        <?php elseif (empty($data['valid'])): ?>
        <div class="alert alert-danger">
            Dieser Link ist ungültig oder abgelaufen.
        </div>
        <a href="/admin/forgot-password" class="btn btn-outline-secondary w-100">
            Neuen Link anfordern
        </a>

        <?php else: ?>

        <?php if (!empty($data['errors'])): ?>
        <div class="alert alert-danger">
            <?php foreach ($data['errors'] as $error): ?>
            <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach ?>
        </div>
        <?php endif ?>

        <div class="card">
            <div class="card-body p-4">
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($data['csrfToken'] ?? '') ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($data['token'] ?? '') ?>">
                    <div class="mb-3">
                        <label class="form-label">Neues Passwort</label>
                        <input type="password" name="password" class="form-control"
                               autocomplete="new-password" autofocus required>
                        <div class="form-text">Mindestens 10 Zeichen</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Passwort bestätigen</label>
                        <input type="password" name="password_confirm" class="form-control"
                               autocomplete="new-password" required>
                    </div>
                    <button class="btn btn-primary w-100">Passwort speichern</button>
                </form>
            </div>
        </div>
        <?php endif ?>
    </div>
</div>
