<?php
/**
 * Content partial for /login (auth.login.render hook).
 *
 * @var array          $data
 * @var \EsseBase\Theme $theme
 */
?>
<div class="row justify-content-center">
    <div class="col-lg-5">
        <?php if (!empty($data['error'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($data['error']) ?></div>
        <?php endif ?>

        <div class="card">
            <div class="card-body p-4">
                <form method="post" action="/login">
                    <input type="hidden" name="_csrf"    value="<?= htmlspecialchars($data['csrfToken'] ?? '') ?>">
                    <input type="hidden" name="_form"    value="admin_login">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($data['redirect'] ?? '') ?>">
                    <div class="mb-3">
                        <label class="form-label">E-Mail</label>
                        <input type="email" name="login" class="form-control" autocomplete="username" autofocus required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Passwort</label>
                        <input type="password" name="password" class="form-control" autocomplete="current-password" required>
                    </div>
                    <button class="btn btn-primary w-100">Anmelden</button>
                </form>

                <div class="d-none mt-3" id="passkey-login-block">
                    <div class="d-flex align-items-center my-3">
                        <hr class="border-secondary flex-grow-1 my-0">
                        <span class="text-secondary small mx-2">oder</span>
                        <hr class="border-secondary flex-grow-1 my-0">
                    </div>
                    <button type="button" id="passkey-login-btn" class="btn btn-outline-light w-100">
                        <i class="bi bi-fingerprint me-1"></i>Mit Passkey anmelden
                    </button>
                    <div class="text-danger small mt-2 d-none" id="passkey-login-error"></div>
                </div>
            </div>
        </div>

        <div class="text-center mt-3 d-flex justify-content-center gap-3">
            <a href="/passwort-vergessen" class="text-secondary small">Passwort vergessen?</a>
            <?php if (!empty($data['registrationEnabled'])): ?>
            <a href="/registrieren" class="text-secondary small">Registrieren</a>
            <?php endif ?>
        </div>
    </div>
</div>

<script type="application/json" id="passkey-login-config"><?= json_encode([
    'csrf'     => $data['csrfToken'] ?? '',
    'redirect' => $data['redirect'] ?? '',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/public/assets/js/webauthn.js"></script>
<script src="/public/assets/js/passkey-login.js"></script>
