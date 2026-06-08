(function () {
    'use strict';

    const btn = document.getElementById('passkey-add-btn');
    const error = document.getElementById('passkey-add-error');
    const configEl = document.getElementById('profile-passkey-config');

    if (!btn || !configEl) return;

    let config = {};
    try {
        config = JSON.parse(configEl.textContent || '{}');
    } catch (e) {
        config = {};
    }

    if (!window.EsseWebAuthn || !EsseWebAuthn.isSupported()) {
        btn.disabled = true;
        btn.title = 'Passkeys werden von diesem Browser nicht unterstützt.';
        return;
    }

    btn.addEventListener('click', async function () {
        error.classList.add('d-none');
        const label = window.prompt('Bezeichnung für diesen Passkey (z.B. "Laptop", "YubiKey"):', '');
        if (label === null) return;

        btn.disabled = true;
        btn.textContent = 'Warte auf Passkey ...';
        try {
            await EsseWebAuthn.register(config.csrf || '', label);
            window.location.reload();
        } catch (e) {
            error.textContent = e.message || 'Passkey konnte nicht hinzugefügt werden.';
            error.classList.remove('d-none');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-fingerprint me-1"></i>Passkey hinzufügen';
        }
    });
})();
