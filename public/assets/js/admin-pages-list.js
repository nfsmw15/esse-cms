(function () {
    'use strict';

    const configEl = document.getElementById('admin-pages-list-config');
    let config = { csrf: '', roles: [] };
    if (configEl) {
        try {
            config = Object.assign(config, JSON.parse(configEl.textContent || '{}'));
        } catch (e) {
            config = { csrf: '', roles: [] };
        }
    }

    const targetDefs = {
        homepage_slug: { label: 'Startseite', color: 'success' },
        login_homepage_slug: { label: 'Login-Start', color: 'primary' },
        logout_page_slug: { label: 'Logoutseite', color: 'info' },
        error_page_slug: { label: 'Fehlerseite', color: 'danger' },
    };

    const visLabels = {
        public: 'Öffentlich',
        guest_only: 'Nur Gäste',
        registered: 'Alle Eingeloggten',
        roles: 'Rollen',
    };

    const visColors = {
        public: 'success',
        guest_only: 'secondary',
        registered: 'primary',
        roles: 'warning',
    };

    function parseJson(value, fallback) {
        try {
            return JSON.parse(value || '');
        } catch (e) {
            return fallback;
        }
    }

    function postAdminPages(fields) {
        const fd = new FormData();
        fd.append('_csrf', config.csrf);
        Object.entries(fields).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach(item => fd.append(key + '[]', item));
            } else {
                fd.append(key, value);
            }
        });

        return fetch('/admin/pages', { method: 'POST', body: fd }).then(response => response.json());
    }

    function updateRolesPanel() {
        const panel = document.getElementById('vm-roles-panel');
        const select = document.getElementById('vm-vis');
        if (panel && select) panel.classList.toggle('admin-hidden', select.value !== 'roles');
    }

    function openVisModal(badge) {
        const slug = badge.dataset.visBadge || '';
        const roles = parseJson(badge.dataset.roles, []);
        document.getElementById('vm-slug').value = slug;
        document.getElementById('vm-type').value = badge.dataset.pageType || 'cms';
        document.getElementById('vm-vis').value = badge.dataset.vis || 'public';
        document.getElementById('vm-title').textContent = badge.dataset.title || slug;

        const list = document.getElementById('vm-roles-list');
        list.innerHTML = '';
        (config.roles || []).forEach(role => {
            const wrapper = document.createElement('div');
            wrapper.className = 'form-check';

            const input = document.createElement('input');
            input.className = 'form-check-input';
            input.type = 'checkbox';
            input.id = 'vmr-' + role.slug;
            input.value = role.slug;
            input.checked = roles.includes(role.slug);

            const label = document.createElement('label');
            label.className = 'form-check-label';
            label.htmlFor = input.id;
            label.textContent = role.label;

            wrapper.append(input, label);
            list.append(wrapper);
        });

        updateRolesPanel();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('visModal')).show();
    }

    async function saveVis() {
        const slug = document.getElementById('vm-slug').value;
        const pageType = document.getElementById('vm-type').value;
        const vis = document.getElementById('vm-vis').value;
        const roles = Array.from(document.querySelectorAll('#vm-roles-list input:checked')).map(el => el.value);
        const btn = document.getElementById('vm-save');
        btn.disabled = true;

        try {
            const data = await postAdminPages({
                _action: 'save_visibility',
                slug,
                page_type: pageType,
                visibility: vis,
                roles,
            });
            if (!data.success) {
                window.alert(data.error || 'Fehler.');
                return;
            }

            const badge = document.querySelector('[data-vis-badge="' + CSS.escape(slug) + '"]');
            if (badge) {
                badge.textContent = visLabels[vis] || vis;
                badge.className = 'badge bg-' + (visColors[vis] || 'secondary') + ' vis-badge';
                badge.dataset.vis = vis;
                badge.dataset.roles = JSON.stringify(roles);
            }
            bootstrap.Modal.getInstance(document.getElementById('visModal'))?.hide();
        } finally {
            btn.disabled = false;
        }
    }

    function openTargetModal(badge) {
        const slug = badge.dataset.targetBadge || '';
        const assigned = parseJson(badge.dataset.assigned, []);
        document.getElementById('tm-slug').value = slug;
        document.querySelectorAll('#targetModal input[type=checkbox]').forEach(cb => {
            cb.checked = assigned.includes(cb.value);
        });
        bootstrap.Modal.getOrCreateInstance(document.getElementById('targetModal')).show();
    }

    async function saveTarget() {
        const slug = document.getElementById('tm-slug').value;
        const targets = Array.from(document.querySelectorAll('#targetModal input[type=checkbox]:checked')).map(el => el.value);
        const btn = document.getElementById('tm-save');
        btn.disabled = true;

        try {
            const data = await postAdminPages({
                _action: 'save_page_target',
                slug,
                targets,
            });
            if (!data.success) {
                window.alert(data.error || 'Fehler.');
                return;
            }

            const badge = document.querySelector('[data-target-badge="' + CSS.escape(slug) + '"]');
            if (badge) {
                badge.dataset.assigned = JSON.stringify(data.assigned);
                badge.innerHTML = data.assigned.length
                    ? data.assigned.map(key => '<span class="badge bg-' + targetDefs[key].color + ' me-1">' + targetDefs[key].label + '</span>').join('')
                    : '<span class="text-secondary target-empty">-</span>';
            }
            bootstrap.Modal.getInstance(document.getElementById('targetModal'))?.hide();
        } finally {
            btn.disabled = false;
        }
    }

    function openIconModal(btn) {
        const slug = btn.dataset.iconSlug || '';
        const icon = btn.dataset.iconCurrent || '';
        document.getElementById('im-slug').value = slug;
        document.getElementById('im-title').textContent = btn.dataset.title || slug;

        const input = document.getElementById('im-icon');
        input.value = icon;
        if (window.esseUpdatePreview) window.esseUpdatePreview(input);

        bootstrap.Modal.getOrCreateInstance(document.getElementById('iconModal')).show();
    }

    async function saveIcon() {
        const slug = document.getElementById('im-slug').value;
        const icon = document.getElementById('im-icon').value.trim();
        const btn = document.getElementById('im-save');
        btn.disabled = true;

        try {
            const data = await postAdminPages({
                _action: 'save_page_icon',
                slug,
                icon,
            });
            if (!data.success) {
                window.alert(data.error || 'Fehler.');
                return;
            }

            const el = document.querySelector('[data-icon-slug="' + CSS.escape(slug) + '"]');
            if (el) {
                el.dataset.iconCurrent = icon;
                el.innerHTML = icon
                    ? '<i class="bi bi-' + icon + ' admin-icon-base"></i>'
                    : '<i class="bi bi-image text-secondary admin-icon-faded admin-icon-base" title="Kein Icon"></i>';
            }
            bootstrap.Modal.getInstance(document.getElementById('iconModal'))?.hide();
        } finally {
            btn.disabled = false;
        }
    }

    document.addEventListener('click', function (event) {
        const visBadge = event.target.closest('[data-vis-badge]');
        if (visBadge) {
            openVisModal(visBadge);
            return;
        }

        const targetBadge = event.target.closest('[data-target-badge]');
        if (targetBadge) {
            openTargetModal(targetBadge);
            return;
        }

        const iconBtn = event.target.closest('[data-icon-slug]');
        if (iconBtn) openIconModal(iconBtn);
    });

    document.getElementById('vm-vis')?.addEventListener('change', updateRolesPanel);
    document.getElementById('vm-save')?.addEventListener('click', saveVis);
    document.getElementById('tm-save')?.addEventListener('click', saveTarget);
    document.getElementById('im-save')?.addEventListener('click', saveIcon);
})();
