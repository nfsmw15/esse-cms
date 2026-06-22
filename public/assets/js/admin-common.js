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

    // Bootstrap unterstützt gestapelte Modals nicht von sich aus (jedes .modal/.modal-backdrop
    // hat denselben festen z-index) — ein über einem bereits offenen Modal geöffnetes Modal landet
    // sonst optisch dahinter. Bei jedem weiteren offenen Modal z-index von Modal + Backdrop anheben.
    document.addEventListener('show.bs.modal', function (event) {
        const openCount = document.querySelectorAll('.modal.show').length;
        if (!openCount) return;

        const zIndex = 1055 + openCount * 20;
        event.target.style.zIndex = String(zIndex);

        setTimeout(function () {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            const topBackdrop = backdrops[backdrops.length - 1];
            if (topBackdrop) topBackdrop.style.zIndex = String(zIndex - 1);
        }, 0);
    });

    document.addEventListener('hidden.bs.modal', function (event) {
        event.target.style.zIndex = '';
    });
})();
