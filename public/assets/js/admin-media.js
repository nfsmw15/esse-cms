(function () {
    'use strict';

    const configEl = document.getElementById('admin-media-config');
    let config = {};
    if (configEl) {
        try {
            config = JSON.parse(configEl.textContent || '{}');
        } catch (e) {
            config = {};
        }
    }

    document.querySelectorAll('.media-filter-autosubmit').forEach(function (el) {
        el.addEventListener('change', function () {
            el.form.submit();
        });
    });

    const editModal = document.getElementById('editMediaModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            if (!btn) return;

            document.getElementById('em-id').value = btn.dataset.id || '';
            document.getElementById('em-path').textContent = btn.dataset.path || '';
            document.getElementById('em-alt').value = btn.dataset.alt || '';
            document.getElementById('em-description').value = btn.dataset.description || '';
            document.getElementById('em-visibility').value = btn.dataset.visibility || 'public';
            document.getElementById('em-folder').value = btn.dataset.folderId || '';
        });
    }

    const lightboxModal = document.getElementById('mediaLightboxModal');
    if (lightboxModal) {
        lightboxModal.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            if (!btn) return;

            const url = btn.dataset.url || '';
            const name = btn.dataset.name || '';

            document.getElementById('lb-image').src = url;
            document.getElementById('lb-name').textContent = name;

            const downloadLink = document.getElementById('lb-download');
            downloadLink.href = url;
            downloadLink.setAttribute('download', name);
        });
    }

    document.querySelectorAll('.media-card-thumb-clickable').forEach(function (el) {
        el.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                el.click();
            }
        });
    });

    const renameFolderModal = document.getElementById('renameFolderModal');
    if (renameFolderModal) {
        renameFolderModal.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            if (!btn) return;
            document.getElementById('rf-id').value = btn.dataset.id || '';
            document.getElementById('rf-name').value = btn.dataset.name || '';
        });
    }

    const deleteFolderModal = document.getElementById('deleteFolderModal');
    if (deleteFolderModal) {
        deleteFolderModal.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            if (!btn) return;
            document.getElementById('df-id').value = btn.dataset.id || '';
            document.getElementById('df-name').textContent = btn.dataset.name || '';
        });
    }

    const deleteModal = document.getElementById('deleteMediaModal');
    document.querySelectorAll('.media-delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = btn.dataset.id;
            const name = btn.dataset.name;

            document.getElementById('dm-id').value = id;
            document.getElementById('dm-name').textContent = name;

            const usagesEl = document.getElementById('dm-usages');
            usagesEl.innerHTML = '<p class="text-secondary small mb-0">Prüfe Verwendung…</p>';

            const fd = new FormData();
            fd.append('_csrf', config.csrf || '');
            fd.append('_action', 'usages');
            fd.append('id', id);

            fetch('/admin/media', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    const pages = data.pages || [];
                    if (pages.length) {
                        usagesEl.innerHTML = '<div class="alert alert-warning small mb-0">'
                            + 'Diese Datei wird möglicherweise noch verwendet in: '
                            + pages.map(p => '<strong>' + escapeHtml(p.title) + '</strong>').join(', ')
                            + '</div>';
                    } else {
                        usagesEl.innerHTML = '';
                    }
                })
                .catch(() => { usagesEl.innerHTML = ''; });

            bootstrap.Modal.getOrCreateInstance(deleteModal).show();
        });
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
})();
