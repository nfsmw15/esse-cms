(function () {
    'use strict';

    let activeCallback = null;
    let items = null;

    window.EsseShortcode = {
        open: function (callback) {
            activeCallback = callback;

            const modalEl = document.getElementById('esseShortcodePickerModal');
            if (!modalEl) return;

            if (!items) {
                loadItems();
            } else {
                renderList();
            }

            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        },
    };

    function loadItems() {
        setStatus('Lade Widgets…');

        fetch('/admin/shortcodes/list')
            .then(r => r.json())
            .then(data => {
                items = data.items && typeof data.items === 'object' ? data.items : {};
                renderList();
            })
            .catch(() => setStatus('Widgets konnten nicht geladen werden.'));
    }

    function setStatus(message) {
        const status = document.getElementById('esseShortcodeStatus');
        const list = document.getElementById('esseShortcodeList');
        if (status) {
            status.textContent = message;
            status.classList.toggle('admin-hidden', !message);
        }
        if (list) list.innerHTML = '';
    }

    function renderList() {
        const list = document.getElementById('esseShortcodeList');
        if (!list) return;

        const tags = Object.keys(items || {});
        if (!tags.length) {
            setStatus('Keine Widgets verfügbar.');
            return;
        }

        setStatus('');

        list.innerHTML = tags.map(tag => {
            const meta = items[tag] || {};
            const attributes = Array.isArray(meta.attributes) ? meta.attributes : [];

            const inputs = attributes.map(attr => {
                const id = 'esseShortcodeAttr_' + tag + '_' + attr.name;
                const type = attr.type === 'number' ? 'number' : 'text';
                return '<div class="col-auto">'
                    + '<label for="' + id + '" class="form-label small mb-1">' + escapeHtml(attr.label || attr.name) + '</label>'
                    + '<input type="' + type + '" class="form-control form-control-sm" id="' + id + '" '
                    + 'data-attr="' + escapeAttr(attr.name) + '" value="' + escapeAttr(attr.default ?? '') + '">'
                    + '</div>';
            }).join('');

            return '<div class="list-group-item bg-dark border-secondary">'
                + '<div class="d-flex justify-content-between align-items-start gap-3">'
                + '<div>'
                + '<div class="fw-semibold">' + escapeHtml(meta.label || tag) + '</div>'
                + (meta.description ? '<div class="text-secondary small">' + escapeHtml(meta.description) + '</div>' : '')
                + '</div>'
                + '<button type="button" class="btn btn-primary btn-sm flex-shrink-0 esse-shortcode-insert" data-tag="' + escapeAttr(tag) + '">'
                + '<i class="bi bi-plus-lg"></i> Einfügen</button>'
                + '</div>'
                + (inputs ? '<div class="row row-cols-auto g-2 mt-2">' + inputs + '</div>' : '')
                + '</div>';
        }).join('');
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
    function escapeAttr(s) {
        return escapeHtml(s);
    }

    document.getElementById('esseShortcodeList')?.addEventListener('click', function (event) {
        const btn = event.target.closest('.esse-shortcode-insert');
        if (!btn || !activeCallback) return;

        const tag = btn.dataset.tag;
        const item = btn.closest('.list-group-item');
        const attrs = [];

        item?.querySelectorAll('[data-attr]').forEach(input => {
            const value = input.value.trim();
            if (value !== '') {
                attrs.push(input.dataset.attr + '="' + value.replace(/"/g, '&quot;') + '"');
            }
        });

        const code = '[' + tag + (attrs.length ? ' ' + attrs.join(' ') : '') + ']';
        activeCallback(code);

        bootstrap.Modal.getInstance(document.getElementById('esseShortcodePickerModal'))?.hide();
    });
})();
