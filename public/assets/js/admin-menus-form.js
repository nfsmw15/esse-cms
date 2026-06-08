(function () {
    'use strict';

    const configEl = document.getElementById('admin-menus-form-config');
    let config = { csrf: '', reorderUrl: '' };
    if (configEl) {
        try {
            config = Object.assign(config, JSON.parse(configEl.textContent || '{}'));
        } catch (e) {
            config = { csrf: '', reorderUrl: '' };
        }
    }

    function updateAddFields() {
        const typeEl = document.getElementById('item-type');
        const pgField = document.getElementById('field-page');
        const urlField = document.getElementById('field-url');
        const tgtField = document.getElementById('field-target');
        if (!typeEl) return;

        const value = typeEl.value;
        if (pgField) pgField.style.display = value === 'page' ? '' : 'none';
        if (urlField) urlField.style.display = value === 'url' ? '' : 'none';
        if (tgtField) tgtField.style.display = value !== 'header' ? '' : 'none';
    }

    function collectOrder() {
        const items = [];
        let order = 10;
        document.querySelectorAll('#sortable-top > [data-id]').forEach(el => {
            items.push({ id: parseInt(el.dataset.id, 10), parent_id: 0, order });
            order += 10;

            let childOrder = 10;
            el.querySelectorAll('[data-child-id]').forEach(child => {
                items.push({
                    id: parseInt(child.dataset.childId, 10),
                    parent_id: parseInt(el.dataset.id, 10),
                    order: childOrder,
                });
                childOrder += 10;
            });
        });
        return items;
    }

    function saveOrder() {
        if (!config.reorderUrl) return;

        const fd = new FormData();
        fd.append('_csrf', config.csrf);
        fd.append('_action', 'reorder');
        fd.append('items', JSON.stringify(collectOrder()));
        fetch(config.reorderUrl, { method: 'POST', body: fd })
            .then(response => response.json())
            .catch(() => {});
    }

    function initSortable() {
        const topList = document.getElementById('sortable-top');
        if (!topList || !window.Sortable) return;

        Sortable.create(topList, {
            handle: '.drag-handle',
            animation: 150,
            onEnd: saveOrder,
        });

        document.querySelectorAll('.sortable-children').forEach(el => {
            Sortable.create(el, {
                handle: '.drag-handle',
                animation: 150,
                onEnd: saveOrder,
            });
        });
    }

    document.getElementById('item-type')?.addEventListener('change', updateAddFields);
    updateAddFields();

    document.addEventListener('change', function (event) {
        const select = event.target.closest('.item-type-sel');
        if (!select) return;

        const form = select.closest('form');
        form?.querySelector('.field-page')?.classList.toggle('d-none', select.value !== 'page');
        form?.querySelector('.field-url')?.classList.toggle('d-none', select.value !== 'url');
    });

    initSortable();
})();
