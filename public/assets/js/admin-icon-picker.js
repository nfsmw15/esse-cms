(function () {
    'use strict';

    const configEl = document.getElementById('admin-icon-picker-config');
    let config = { prefix: 'bi bi-' };
    if (configEl) {
        try {
            config = Object.assign(config, JSON.parse(configEl.textContent || '{}'));
        } catch (e) {
            config = { prefix: 'bi bi-' };
        }
    }

    const iconPrefix = config.prefix || 'bi bi-';
    let target = null;
    let icons = null;

    window.esseOpenIconPicker = function (inputEl) {
        target = inputEl;

        const search = document.getElementById('esseIconSearch');
        if (search) search.value = '';

        const modalEl = document.getElementById('esseIconPickerModal');
        if (!modalEl) return;

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        if (icons) {
            renderGrid('');
        } else {
            setStatus('Lade Icon-Liste...');
            fetch('/admin/iconpacks/icons')
                .then(r => r.json())
                .then(data => {
                    icons = Array.isArray(data.icons) ? data.icons : [];
                    renderGrid('');
                })
                .catch(() => setStatus('Icon-Liste konnte nicht geladen werden.'));
        }

        modal.show();
        modalEl.addEventListener('shown.bs.modal', function focus() {
            document.getElementById('esseIconSearch')?.focus();
            modalEl.removeEventListener('shown.bs.modal', focus);
        });
    };

    window.esseUpdatePreview = function (inputEl) {
        const prev = document.querySelector('.esse-icon-preview[data-for="' + inputEl.id + '"]');
        if (!prev) return;

        const name = inputEl.value.trim();
        if (!name) {
            prev.innerHTML = '<i class="bi bi-grid-3x3-gap esse-icon-muted"></i>';
            prev.title = 'Icon wählen';
            return;
        }

        const cls = name.includes(' ') ? name : iconPrefix + name;
        prev.innerHTML = '<i class="' + cls + ' esse-icon-preview-glyph"></i>';
        prev.title = name;
    };

    function setStatus(message) {
        const status = document.getElementById('esseIconStatus');
        const grid = document.getElementById('esseIconGrid');
        if (status) {
            status.textContent = message;
            status.style.display = '';
        }
        if (grid) grid.innerHTML = '';
    }

    function searchIcons(query) {
        if (!icons) return [];
        const q = query.toLowerCase().trim();
        if (!q) return icons;
        return icons.filter(name => name.includes(q));
    }

    function renderGrid(filter) {
        const grid = document.getElementById('esseIconGrid');
        const status = document.getElementById('esseIconStatus');
        if (!grid) return;

        const list = searchIcons(filter);
        if (!list.length) {
            setStatus(filter ? 'Keine Icons gefunden.' : 'Keine Icons verfügbar.');
            return;
        }

        if (status) status.style.display = 'none';
        grid.innerHTML = list.map(name =>
            '<button type="button" class="btn btn-outline-secondary p-1 esse-ipick-btn" data-name="' + name + '" title="' + name + '">' +
            '<i class="' + iconPrefix + name + ' esse-ipick-glyph"></i>' +
            '</button>'
        ).join('');
    }

    document.getElementById('esseIconSearch')?.addEventListener('input', function () {
        if (icons) renderGrid(this.value);
    });

    document.getElementById('esseIconGrid')?.addEventListener('click', function (event) {
        const btn = event.target.closest('.esse-ipick-btn');
        if (!btn || !target) return;
        target.value = btn.dataset.name;
        target.dispatchEvent(new Event('input', { bubbles: true }));
        bootstrap.Modal.getInstance(document.getElementById('esseIconPickerModal'))?.hide();
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[data-icon-preview]').forEach(function (input) {
            if (input.value) window.esseUpdatePreview(input);
        });
    });
})();
