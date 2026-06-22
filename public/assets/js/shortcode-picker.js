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

                if (attr.type === 'images') {
                    return '<div class="col-12">'
                        + '<label class="form-label small mb-1">' + escapeHtml(attr.label || attr.name) + '</label>'
                        + '<div class="esse-shortcode-image-chips" id="' + id + '_chips"></div>'
                        + '<button type="button" class="btn btn-secondary btn-sm esse-shortcode-image-add" data-target="' + id + '">'
                        + '<i class="bi bi-plus-lg"></i> Bild hinzufügen</button>'
                        + '<input type="hidden" id="' + id + '" data-attr="' + escapeAttr(attr.name) + '" value="' + escapeAttr(attr.default ?? '') + '">'
                        + '</div>';
                }

                if (attr.type === 'select') {
                    const options = Array.isArray(attr.options) ? attr.options : [];
                    const optionsHtml = options.map(opt =>
                        '<option value="' + escapeAttr(opt.value) + '"' + (opt.value === attr.default ? ' selected' : '') + '>'
                        + escapeHtml(opt.label || opt.value) + '</option>'
                    ).join('');
                    return '<div class="col-auto">'
                        + '<label for="' + id + '" class="form-label small mb-1">' + escapeHtml(attr.label || attr.name) + '</label>'
                        + '<select class="form-select form-select-sm" id="' + id + '" data-attr="' + escapeAttr(attr.name) + '">'
                        + optionsHtml + '</select>'
                        + '</div>';
                }

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

    function renderImageChip(file) {
        return '<span class="esse-shortcode-image-chip" data-id="' + escapeAttr(file.id) + '">'
            + '<img src="' + escapeAttr(file.url) + '" alt="">'
            + '<button type="button" class="esse-shortcode-image-chip-remove" aria-label="Entfernen">&times;</button>'
            + '</span>';
    }

    document.getElementById('esseShortcodeList')?.addEventListener('click', function (event) {
        const addBtn = event.target.closest('.esse-shortcode-image-add');
        if (addBtn) {
            const hiddenInput = document.getElementById(addBtn.dataset.target);
            const chips = document.getElementById(addBtn.dataset.target + '_chips');
            if (!hiddenInput || !chips || !window.EsseMedia) return;

            window.EsseMedia.open(function (file) {
                const ids = hiddenInput.value ? hiddenInput.value.split(',').filter(Boolean) : [];
                if (file.id && !ids.includes(String(file.id))) {
                    ids.push(String(file.id));
                    hiddenInput.value = ids.join(',');
                    chips.insertAdjacentHTML('beforeend', renderImageChip(file));
                }
            }, { type: 'image', warnPrivate: true });
            return;
        }

        const removeBtn = event.target.closest('.esse-shortcode-image-chip-remove');
        if (removeBtn) {
            const chip = removeBtn.closest('.esse-shortcode-image-chip');
            const wrapper = chip?.closest('.col-12');
            const hiddenInput = wrapper?.querySelector('input[type="hidden"][data-attr]');
            if (chip && hiddenInput) {
                const removedId = chip.dataset.id;
                const ids = hiddenInput.value.split(',').filter(id => id && id !== removedId);
                hiddenInput.value = ids.join(',');
                chip.remove();
            }
            return;
        }
    });

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
