(function () {
    'use strict';

    const nameEl = document.getElementById('menu-name');
    const slugEl = document.getElementById('menu-slug');
    if (!nameEl || !slugEl) return;

    let slugEdited = slugEl.value.length > 0;

    function slugify(str) {
        return str.toLowerCase()
            .replace(/[äöü]/g, c => ({ ä: 'ae', ö: 'oe', ü: 'ue' }[c] || c))
            .replace(/ß/g, 'ss')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    nameEl.addEventListener('input', () => {
        if (!slugEdited) slugEl.value = slugify(nameEl.value);
    });

    slugEl.addEventListener('input', () => {
        slugEdited = slugEl.value.length > 0;
    });
})();
