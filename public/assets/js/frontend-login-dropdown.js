(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const configEl = document.getElementById('frontend-login-config');
        if (!configEl) return;

        let config = {};
        try {
            config = JSON.parse(configEl.textContent || '{}');
        } catch (e) {
            return;
        }

        if (!config.loginFailed) return;

        const toggle = document.getElementById('navbar-login-toggle');
        const form = document.getElementById('navbar-login-form');
        if (!toggle || !form) return;

        bootstrap.Dropdown.getOrCreateInstance(toggle, {
            autoClose: 'outside',
        }).show();

        const password = form.querySelector('input[name="password"]');
        if (password) password.focus();
    });
})();
