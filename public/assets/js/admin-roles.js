(function () {
    'use strict';

    const configEl = document.getElementById('admin-roles-config');
    if (!configEl) return;

    let config = {};
    try {
        config = JSON.parse(configEl.textContent || '{}');
    } catch (e) {
        return;
    }

    document.querySelectorAll('.perm-toggle').forEach(btn => {
        btn.addEventListener('click', async () => {
            const fd = new FormData();
            fd.append('_csrf', config.csrf || '');
            fd.append('_action', 'toggle_permission');
            fd.append('role_id', btn.dataset.role || '');
            fd.append('permission', btn.dataset.perm || '');

            const res = await fetch('/admin/roles', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.granted) {
                btn.classList.replace('bg-dark', 'bg-success');
                btn.classList.remove('border');
            } else {
                btn.classList.replace('bg-success', 'bg-dark');
                btn.classList.add('border');
            }
        });
    });
})();
