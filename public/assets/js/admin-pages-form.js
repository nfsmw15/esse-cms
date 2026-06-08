(function () {
    'use strict';

    const configEl = document.getElementById('admin-pages-form-config');
    let config = {};
    if (configEl) {
        try {
            config = JSON.parse(configEl.textContent || '{}');
        } catch (e) {
            config = {};
        }
    }

    const visSelect = document.getElementById('form-vis-select');
    const visPanel = document.getElementById('form-vis-roles');
    function updateVisRoles() {
        if (visPanel && visSelect) visPanel.style.display = visSelect.value === 'roles' ? '' : 'none';
    }
    visSelect?.addEventListener('change', updateVisRoles);
    updateVisRoles();

    const titleEl = document.getElementById('title');
    const slugEl = document.getElementById('slug');
    let slugEdited = !!config.slugEdited;

    function slugify(str) {
        const map = { ä: 'ae', ö: 'oe', ü: 'ue', Ä: 'ae', Ö: 'oe', Ü: 'ue', ß: 'ss' };
        return str.toLowerCase()
            .replace(/[äöüÄÖÜß]/g, c => map[c] || c)
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    titleEl?.addEventListener('input', () => {
        if (!slugEdited && slugEl) slugEl.value = slugify(titleEl.value);
    });
    slugEl?.addEventListener('input', () => {
        slugEdited = slugEl.value.length > 0;
    });

    const typeEl = document.getElementById('type');
    const contentCard = document.getElementById('content-card');
    const phpCard = document.getElementById('php-card');

    function updateType() {
        if (!typeEl) return;
        if (typeEl.value === 'php') {
            contentCard?.style.setProperty('display', 'none', 'important');
            phpCard?.style.removeProperty('display');
        } else {
            contentCard?.style.removeProperty('display');
            phpCard?.style.setProperty('display', 'none', 'important');
        }
    }
    typeEl?.addEventListener('change', updateType);
    updateType();
})();
