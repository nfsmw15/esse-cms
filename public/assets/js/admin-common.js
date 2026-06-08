(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        const form = event.target.closest('form[data-confirm]');
        if (!form) return;

        const message = form.dataset.confirm || 'Aktion wirklich ausführen?';
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });

    document.addEventListener('change', function (event) {
        const el = event.target.closest('[data-submit-on-change]');
        if (el && el.form) el.form.submit();
    });

    document.addEventListener('click', function (event) {
        const picker = event.target.closest('[data-icon-picker-target]');
        if (picker && window.esseOpenIconPicker) {
            const input = document.getElementById(picker.dataset.iconPickerTarget || '');
            if (input) window.esseOpenIconPicker(input);
        }
    });

    document.addEventListener('input', function (event) {
        const input = event.target.closest('input[data-icon-preview]');
        if (input && window.esseUpdatePreview) {
            window.esseUpdatePreview(input);
        }
    });
})();
