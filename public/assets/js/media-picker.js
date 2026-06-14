(function () {
    'use strict';

    const configEl = document.getElementById('admin-media-picker-config');
    let config = {};
    if (configEl) {
        try {
            config = JSON.parse(configEl.textContent || '{}');
        } catch (e) {
            config = {};
        }
    }

    let activeCallback = null;
    let activeOptions  = {};
    let items = null;

    window.EsseMedia = {
        open: function (callback, options) {
            activeCallback = callback;
            activeOptions  = options || {};

            const modalEl = document.getElementById('esseMediaPickerModal');
            if (!modalEl) return;

            const search = document.getElementById('esseMediaSearch');
            if (search) search.value = '';

            items = null;
            loadItems('');

            const status = document.getElementById('esseMediaUploadStatus');
            if (status) status.textContent = '';
            const fileInput = document.getElementById('esseMediaUploadFile');
            if (fileInput) fileInput.value = '';

            bootstrap.Modal.getOrCreateInstance(modalEl).show();
            modalEl.addEventListener('shown.bs.modal', function focus() {
                search?.focus();
                modalEl.removeEventListener('shown.bs.modal', focus);
            });
        },
    };

    function loadItems(query) {
        setStatus('Lade Mediathek…');

        const params = new URLSearchParams();
        if (activeOptions.type) params.set('type', activeOptions.type);
        if (query) params.set('q', query);

        fetch('/admin/media/list?' + params.toString())
            .then(r => r.json())
            .then(data => {
                items = Array.isArray(data.items) ? data.items : [];
                renderGrid();
            })
            .catch(() => setStatus('Mediathek konnte nicht geladen werden.'));
    }

    function setStatus(message) {
        const status = document.getElementById('esseMediaStatus');
        const grid = document.getElementById('esseMediaGrid');
        if (status) {
            status.textContent = message;
            status.classList.toggle('admin-hidden', !message);
        }
        if (grid) grid.innerHTML = '';
    }

    function renderGrid() {
        const grid = document.getElementById('esseMediaGrid');
        if (!grid) return;

        if (!items || !items.length) {
            setStatus('Keine Dateien gefunden.');
            return;
        }

        setStatus('');

        grid.innerHTML = items.map(item => {
            const thumb = item.type === 'image'
                ? '<img src="' + item.url + '" class="media-thumb" loading="lazy" alt="">'
                : '<div class="media-thumb media-thumb-file"><i class="bi bi-file-earmark-text"></i></div>';
            const privateBadge = item.visibility === 'private'
                ? '<span class="badge bg-secondary media-private-badge"><i class="bi bi-lock-fill"></i> Privat</span>'
                : '';

            return '<div class="media-card media-picker-item" role="button" '
                + 'data-id="' + item.id + '" data-url="' + item.url + '" '
                + 'data-alt="' + escapeAttr(item.alt || '') + '" '
                + 'data-name="' + escapeAttr(item.filename) + '" '
                + 'data-visibility="' + item.visibility + '">'
                + '<div class="media-card-thumb">' + thumb + privateBadge + '</div>'
                + '<div class="media-card-body"><div class="media-card-name" title="' + escapeAttr(item.filename) + '">' + escapeHtml(item.filename) + '</div></div>'
                + '</div>';
        }).join('');
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
    function escapeAttr(s) {
        return escapeHtml(s);
    }

    let searchTimer = null;
    document.getElementById('esseMediaSearch')?.addEventListener('input', function () {
        clearTimeout(searchTimer);
        const q = this.value;
        searchTimer = setTimeout(() => loadItems(q), 250);
    });

    document.getElementById('esseMediaGrid')?.addEventListener('click', function (event) {
        const card = event.target.closest('.media-picker-item');
        if (!card || !activeCallback) return;

        if (card.dataset.visibility === 'private' && activeOptions.warnPrivate) {
            const ok = confirm('Diese Datei ist als „Privat" markiert und für Besucher nicht öffentlich erreichbar. Trotzdem verwenden?');
            if (!ok) return;
        }

        activeCallback({
            id: card.dataset.id,
            url: card.dataset.url,
            alt: card.dataset.alt,
            name: card.dataset.name,
            visibility: card.dataset.visibility,
        });

        bootstrap.Modal.getInstance(document.getElementById('esseMediaPickerModal'))?.hide();
    });

    document.getElementById('esseMediaUploadBtn')?.addEventListener('click', function () {
        const fileInput = document.getElementById('esseMediaUploadFile');
        const status = document.getElementById('esseMediaUploadStatus');
        if (!fileInput || !fileInput.files[0]) return;

        status.textContent = 'Wird hochgeladen…';

        const fd = new FormData();
        fd.append('file', fileInput.files[0]);
        fd.append('_csrf', config.csrf || '');

        fetch('/admin/files/upload', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.url && activeCallback) {
                    activeCallback({
                        id: null,
                        url: data.url,
                        alt: '',
                        name: fileInput.files[0].name,
                        visibility: 'public',
                    });
                    bootstrap.Modal.getInstance(document.getElementById('esseMediaPickerModal'))?.hide();
                } else {
                    status.textContent = data.error || 'Upload fehlgeschlagen.';
                }
            })
            .catch(() => { status.textContent = 'Upload fehlgeschlagen.'; });
    });
})();
