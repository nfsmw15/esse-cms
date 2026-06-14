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

    function hasRealChildren(itemEl) {
        const container = itemEl.querySelector(':scope > .sortable-children:not(.sortable-children-inline)');
        if (!container) return false;
        return container.querySelectorAll(':scope > [data-id]').length > 0;
    }

    function makeInlineTarget(itemEl) {
        const div = document.createElement('div');
        div.className = 'sortable-children sortable-children-inline';
        div.dataset.parentId = itemEl.dataset.id;
        div.title = 'Element hierher ziehen, um es als Unterpunkt einzuordnen';
        div.innerHTML = '<i class="bi bi-arrow-return-right"></i> Unterpunkt';
        Sortable.create(div, sortableOptions());
        return div;
    }

    function ensureInlineTarget(itemEl) {
        const row = itemEl.querySelector(':scope > .menu-item-row');
        if (!row || row.querySelector(':scope > .sortable-children-inline')) return;
        const label = row.querySelector(':scope > .menu-item-label');
        const target = makeInlineTarget(itemEl);
        if (label) label.insertAdjacentElement('afterend', target);
        else row.appendChild(target);
    }

    function removeInlineTarget(itemEl) {
        itemEl.querySelector(':scope > .menu-item-row > .sortable-children-inline')?.remove();
    }

    function ensureChildrenContainer(itemEl) {
        let container = itemEl.querySelector(':scope > .sortable-children:not(.sortable-children-inline)');
        if (container) return container;

        container = document.createElement('div');
        container.className = 'sortable-children mt-2 ps-3 border-start border-secondary';
        container.dataset.parentId = itemEl.dataset.id;
        itemEl.appendChild(container);
        Sortable.create(container, sortableOptions());

        removeInlineTarget(itemEl);
        return container;
    }

    function removeChildrenContainerIfEmpty(itemEl) {
        const container = itemEl.querySelector(':scope > .sortable-children:not(.sortable-children-inline)');
        if (container && container.querySelectorAll(':scope > [data-id]').length === 0) {
            container.remove();
            ensureInlineTarget(itemEl);
        }
    }

    let hoveredContainer = null;

    function setHoveredContainer(container) {
        const next = container && container.classList.contains('sortable-children') ? container : null;
        if (hoveredContainer === next) return;
        if (hoveredContainer) hoveredContainer.classList.remove('drag-hover');
        hoveredContainer = next;
        if (hoveredContainer) hoveredContainer.classList.add('drag-hover');
    }

    function handleMove(evt) {
        const dragged = evt.dragged;
        const to = evt.to;

        setHoveredContainer(to);

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
        setHoveredContainer(null);

        const item = evt.item;
        const fromContainer = evt.from;
        let toContainer = evt.to;

        if (toContainer.id === 'sortable-top') {
            ensureInlineTarget(item);
        } else if (toContainer.classList.contains('sortable-children-inline')) {
            const parentItem = toContainer.closest('[data-id]');
            const realContainer = ensureChildrenContainer(parentItem);
            realContainer.appendChild(item);
            toContainer = realContainer;
            removeInlineTarget(item);
        } else if (toContainer.classList.contains('sortable-children')) {
            removeInlineTarget(item);
        }

        if (fromContainer !== toContainer
            && fromContainer.classList.contains('sortable-children')
            && !fromContainer.classList.contains('sortable-children-inline')) {
            removeChildrenContainerIfEmpty(fromContainer.closest('[data-id]'));
        }

        saveOrder();
    }

    function sortableOptions() {
        return {
            group: 'menu-items',
            handle: '.drag-handle',
            animation: 150,
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
