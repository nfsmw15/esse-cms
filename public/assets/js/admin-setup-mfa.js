(function () {
    'use strict';

    const btn = document.getElementById('mfa-passkey-register-btn');
    const error = document.getElementById('mfa-passkey-error');
    const configEl = document.getElementById('admin-setup-mfa-config');

    if (!btn || !error || !configEl || !window.EsseWebAuthn || !EsseWebAuthn.isSupported()) return;

    let config = {};
    try {
        config = JSON.parse(configEl.textContent || '{}');
    } catch (e) {
        return;
    }

    btn.addEventListener('click', async function () {
        error.classList.add('d-none');
        btn.disabled = true;
        btn.textContent = 'Warte auf Passkey ...';

        try {
            const result = await EsseWebAuthn.registerForSetup(config.csrf || '', '');
            window.location.href = result.redirect || '/';
        } catch (e) {
            error.textContent = e.message || 'Passkey-Registrierung fehlgeschlagen.';
            error.classList.remove('d-none');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-fingerprint me-1"></i>Passkey registrieren';
        }
    });
})();
