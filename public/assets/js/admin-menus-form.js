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

    const PLACEHOLDER_TEXT = 'Element hierher ziehen, um es als Unterpunkt einzuordnen';

    function updateAddFields() {
        const typeEl = document.getElementById('item-type');
        const pgField = document.getElementById('field-page');
        const urlField = document.getElementById('field-url');
        const tgtField = document.getElementById('field-target');
        if (!typeEl) return;

        const value = typeEl.value;
        if (pgField) pgField.classList.toggle('admin-hidden', value !== 'page');
        if (urlField) urlField.classList.toggle('admin-hidden', value !== 'url');
        if (tgtField) tgtField.classList.toggle('admin-hidden', value === 'header');
    }

    function collectOrder() {
        const items = [];

        function walk(container, parentId) {
            let order = 10;
            container.querySelectorAll(':scope > [data-id]').forEach(el => {
                const id = parseInt(el.dataset.id, 10);
                items.push({ id, parent_id: parentId, order });
                order += 10;

                const childContainer = el.querySelector(':scope > .sortable-children');
                if (childContainer) walk(childContainer, id);
            });
        }

        const top = document.getElementById('sortable-top');
        if (top) walk(top, 0);
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

    function makePlaceholder() {
        const div = document.createElement('div');
        div.className = 'sortable-placeholder text-secondary small text-center py-2';
        div.textContent = PLACEHOLDER_TEXT;
        return div;
    }

    function updatePlaceholder(container) {
        if (!container || !container.classList.contains('sortable-children')) return;
        const placeholder = container.querySelector('.sortable-placeholder');
        const hasItems = container.querySelectorAll(':scope > [data-id]').length > 0;
        if (placeholder) placeholder.classList.toggle('d-none', hasItems);
    }

    function hasRealChildren(itemEl) {
        const container = itemEl.querySelector(':scope > .sortable-children');
        if (!container) return false;
        return container.querySelectorAll(':scope > [data-id]').length > 0;
    }

    function ensureChildContainer(itemEl) {
        if (itemEl.querySelector(':scope > .sortable-children')) return;

        const container = document.createElement('div');
        container.className = 'sortable-children mt-2 ps-3 border-start border-secondary';
        container.dataset.parentId = itemEl.dataset.id;
        container.appendChild(makePlaceholder());
        itemEl.appendChild(container);

        Sortable.create(container, sortableOptions());
    }

    function removeChildContainer(itemEl) {
        const container = itemEl.querySelector(':scope > .sortable-children');
        if (container && container.querySelectorAll(':scope > [data-id]').length === 0) {
            container.remove();
        }
    }

    function handleMove(evt) {
        const dragged = evt.dragged;
        const to = evt.to;

        if (to.classList.contains('sortable-children')) {
            // No nesting beyond two levels
            if (to.parentElement.closest('.sortable-children')) return false;
            // An item can't become its own parent
            if (to.dataset.parentId === dragged.dataset.id) return false;
            // Items that have their own children can't become children themselves
            if (hasRealChildren(dragged)) return false;
        }
        return true;
    }

    function handleEnd(evt) {
        const item = evt.item;
        const fromContainer = evt.from;
        const toContainer = evt.to;

        if (toContainer.id === 'sortable-top') {
            ensureChildContainer(item);
        } else if (toContainer.classList.contains('sortable-children')) {
            removeChildContainer(item);
        }

        updatePlaceholder(fromContainer);
        updatePlaceholder(toContainer);

        saveOrder();
    }

    function sortableOptions() {
        return {
            group: 'menu-items',
            handle: '.drag-handle',
            animation: 150,
            filter: '.sortable-placeholder',
            onMove: handleMove,
            onEnd: handleEnd,
        };
    }

    function initSortable() {
        const topList = document.getElementById('sortable-top');
        if (!topList || !window.Sortable) return;

        Sortable.create(topList, sortableOptions());

        document.querySelectorAll('.sortable-children').forEach(el => {
            Sortable.create(el, sortableOptions());
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
