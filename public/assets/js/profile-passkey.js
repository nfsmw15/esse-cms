(function () {
    'use strict';

    const btn = document.getElementById('passkey-add-btn');
    const error = document.getElementById('passkey-add-error');
    const configEl = document.getElementById('profile-passkey-config');
    const confirmBtn = document.getElementById('passkey-add-confirm');
    const labelInput = document.getElementById('passkey-add-label');
    const passwordInput = document.getElementById('passkey-add-password');
    const modalEl = document.getElementById('passkeyAddModal');

    if (!btn || !configEl || !confirmBtn || !modalEl) return;

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

    confirmBtn.addEventListener('click', async function () {
        error.classList.add('d-none');
        const label = (labelInput && labelInput.value) || '';
        const password = (passwordInput && passwordInput.value) || '';
        if (!password) {
            passwordInput.focus();
            return;
        }

        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Warte auf Passkey ...';
        try {
            await EsseWebAuthn.register(config.csrf || '', label, password);
            window.location.reload();
        } catch (e) {
            const modal = window.bootstrap && bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
            error.textContent = e.message || 'Passkey konnte nicht hinzugefügt werden.';
            error.classList.remove('d-none');
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Weiter';
            if (passwordInput) passwordInput.value = '';
        }
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        if (passwordInput) passwordInput.value = '';
    });
})();
