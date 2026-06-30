(function () {
    'use strict';

    const configEl = document.getElementById('admin-users-list-config');
    let config = { csrf: '' };
    if (configEl) {
        try {
            config = Object.assign(config, JSON.parse(configEl.textContent || '{}'));
        } catch (e) {
            config = { csrf: '' };
        }
    }

    function openApproveModal(badge) {
        document.getElementById('am-user-id').value = badge.dataset.approveUser || '';
        document.getElementById('am-name').textContent = badge.dataset.name || '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('approveModal')).show();
    }

    async function saveApprove() {
        const userId = document.getElementById('am-user-id').value;
        const btn = document.getElementById('am-save');
        btn.disabled = true;

        try {
            const fd = new FormData();
            fd.append('_csrf', config.csrf);
            fd.append('_action', 'approve_user');
            fd.append('user_id', userId);

            const res = await fetch('/admin/users', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.success) {
                window.alert(data.error || 'Fehler.');
                return;
            }

            const badge = document.querySelector('[data-approve-user="' + CSS.escape(userId) + '"]');
            if (badge) {
                badge.outerHTML = '<span class="badge bg-success">Aktiv</span>';
            }
            bootstrap.Modal.getInstance(document.getElementById('approveModal'))?.hide();
        } finally {
            btn.disabled = false;
        }
    }

    document.addEventListener('click', function (event) {
        const badge = event.target.closest('[data-approve-user]');
        if (badge) openApproveModal(badge);
    });

    document.getElementById('am-save')?.addEventListener('click', saveApprove);
})();
