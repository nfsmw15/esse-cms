<?php
/**
 * Content partial for /admin/forgot-password (auth.forgot_password.render hook).
 *
 * @var array $data
 */
?>
<div class="row justify-content-center">
    <div class="col-lg-5">
        <?php if (!empty($data['sent'])): ?>
        <div class="alert alert-success">
            Falls ein Account mit dieser E-Mail-Adresse existiert, wurde ein Reset-Link versendet.
            Bitte prüfe deinen Posteingang.
        </div>
        <a href="/login" class="btn btn-primary w-100">Zum Login</a>

        <?php else: ?>

        <?php if (!empty($data['errors'])): ?>
        <div class="alert alert-danger">
            <?php foreach ($data['errors'] as $error): ?>
            <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach ?>
        </div>
        <?php endif ?>

        <p class="text-secondary">
            Gib deine E-Mail-Adresse ein. Du erhältst einen Link zum Zurücksetzen deines Passworts.
        </p>

        <div class="card">
            <div class="card-body p-4">
                <form method="post" action="/admin/forgot-password">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($data['csrfToken'] ?? '') ?>">
                    <div class="mb-3">
                        <label class="form-label">E-Mail</label>
                        <input type="email" name="email" class="form-control" autocomplete="username" autofocus required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label"><?= htmlspecialchars($data['captchaQuestion'] ?? '') ?> = ?</label>
                        <input type="text" name="captcha_answer" class="form-control" inputmode="numeric" autocomplete="off" required>
                    </div>
                    <div class="esse-honeypot" aria-hidden="true" hidden>
                        <label for="forgot-website">Website</label>
                        <input type="text" id="forgot-website"
                               name="<?= htmlspecialchars($data['honeypotField'] ?? '') ?>"
                               tabindex="-1" autocomplete="off">
                    </div>
                    <button class="btn btn-primary w-100">Link senden</button>
                </form>
            </div>
        </div>

        <div class="text-center mt-3">
            <a href="/login" class="text-secondary small">Zurück zum Login</a>
        </div>
        <?php endif ?>
    </div>
</div>
