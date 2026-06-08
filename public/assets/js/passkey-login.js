(function () {
    'use strict';

    const btn = document.getElementById('passkey-login-btn');
    const block = document.getElementById('passkey-login-block');
    const error = document.getElementById('passkey-login-error');
    const configEl = document.getElementById('passkey-login-config');

    if (!btn || !block || !configEl || !window.EsseWebAuthn || !EsseWebAuthn.isSupported()) return;

    let config = {};
    try {
        config = JSON.parse(configEl.textContent || '{}');
    } catch (e) {
        return;
    }

    block.classList.remove('d-none');

    btn.addEventListener('click', async function () {
        error.classList.add('d-none');
        btn.disabled = true;
        btn.textContent = 'Warte auf Passkey ...';

        try {
            const result = await EsseWebAuthn.login(config.csrf || '', config.redirect || '');
            window.location.href = result.redirect || '/';
        } catch (e) {
            error.textContent = e.message || 'Anmeldung mit Passkey fehlgeschlagen.';
            error.classList.remove('d-none');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-fingerprint me-1"></i>Mit Passkey anmelden';
        }
    });
})();
