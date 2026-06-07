// WebAuthn/Passkey-Hilfsfunktionen — Vanilla JS, keine Bibliothek (Browser-native
// navigator.credentials-API). Wird sowohl von der Login-Seite ("Mit Passkey anmelden")
// als auch von /profil ("Passkey hinzufügen") eingebunden.
//
// Server (core/WebAuthn.php) liefert/erwartet binäre Felder als Base64URL-Strings
// (ByteBuffer::$useBase64UrlEncoding = true) — dieses Modul übernimmt die Konvertierung
// zu/von ArrayBuffer, die die navigator.credentials-API erwartet.
(function (global) {
    'use strict';

    function base64UrlToBuffer(base64url) {
        const padded = base64url.replace(/-/g, '+').replace(/_/g, '/');
        const padLen = (4 - (padded.length % 4)) % 4;
        const base64 = padded + '='.repeat(padLen);
        const binary = atob(base64);
        const bytes  = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        return bytes.buffer;
    }

    function bufferToBase64Url(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    // Wandelt rekursiv alle Base64URL-Strings in den vom Server gelieferten Options-Objekten
    // (challenge, user.id, excludeCredentials[].id, allowCredentials[].id, ...) in ArrayBuffer um.
    function decodeOptions(options) {
        const out = Object.assign({}, options);
        if (out.challenge) out.challenge = base64UrlToBuffer(out.challenge);

        if (out.user) {
            out.user = Object.assign({}, out.user);
            if (out.user.id) out.user.id = base64UrlToBuffer(out.user.id);
        }
        for (const key of ['excludeCredentials', 'allowCredentials']) {
            if (Array.isArray(out[key])) {
                out[key] = out[key].map(function (cred) {
                    return Object.assign({}, cred, { id: base64UrlToBuffer(cred.id) });
                });
            }
        }
        return out;
    }

    // Wandelt eine PublicKeyCredential-Antwort des Browsers in ein JSON-serialisierbares
    // Objekt mit Base64URL-Strings um (Gegenstück zu decodeOptions).
    function encodeCredential(credential) {
        const response = credential.response;
        const isRegistration = typeof response.attestationObject !== 'undefined';

        const result = {
            id:    credential.id,
            rawId: bufferToBase64Url(credential.rawId),
            type:  credential.type,
            response: isRegistration
                ? {
                    clientDataJSON:    bufferToBase64Url(response.clientDataJSON),
                    attestationObject: bufferToBase64Url(response.attestationObject),
                  }
                : {
                    clientDataJSON:    bufferToBase64Url(response.clientDataJSON),
                    authenticatorData: bufferToBase64Url(response.authenticatorData),
                    signature:         bufferToBase64Url(response.signature),
                    userHandle:        response.userHandle ? bufferToBase64Url(response.userHandle) : null,
                  },
        };
        return result;
    }

    async function postJson(url, csrfToken, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type':  'application/json',
                'X-CSRF-Token':  csrfToken,
            },
            body: JSON.stringify(body || {}),
        });
        let data;
        try { data = await res.json(); } catch (e) { data = null; }
        if (!res.ok || !data || data.error) {
            throw new Error((data && data.error) || ('Anfrage fehlgeschlagen (HTTP ' + res.status + ')'));
        }
        return data;
    }

    function isSupported() {
        return !!(global.PublicKeyCredential && global.navigator && global.navigator.credentials);
    }

    // Registrierungs-Ceremony: Passkey zum aktuellen Konto hinzufügen (von /profil aus).
    async function register(csrfToken, label) {
        const options  = await postJson('/admin/passkey/register-options', csrfToken, {});
        const publicKey = decodeOptions(options.publicKey);

        const credential = await navigator.credentials.create({ publicKey });
        if (!credential) throw new Error('Registrierung abgebrochen.');

        return postJson('/admin/passkey/register-verify', csrfToken, {
            label:      label || '',
            credential: encodeCredential(credential),
        });
    }

    // Authentifizierungs-Ceremony: passwortlose Anmeldung (von der Login-Seite aus).
    // Liefert bei Erfolg { redirect } zurück — der Aufrufer leitet selbst weiter.
    async function login(csrfToken, redirect) {
        const options   = await postJson('/admin/passkey/auth-options', csrfToken, {});
        const publicKey = decodeOptions(options.publicKey);

        const credential = await navigator.credentials.get({ publicKey });
        if (!credential) throw new Error('Anmeldung abgebrochen.');

        return postJson('/admin/passkey/auth-verify', csrfToken, {
            credential: encodeCredential(credential),
            redirect:   redirect || '',
        });
    }

    global.EsseWebAuthn = { isSupported, register, login };
})(window);
