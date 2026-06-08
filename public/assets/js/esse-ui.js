(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        const close = event.target.closest('[data-esse-alert-close]');
        if (close) {
            close.closest('.esse-alert')?.remove();
            return;
        }

        const tab = event.target.closest('[data-esse-tab]');
        if (!tab) return;

        const id = tab.getAttribute('data-esse-tab');
        const tabs = tab.closest('.esse-tabs');
        if (!id || !tabs) return;

        tabs.querySelectorAll('.esse-tabs-panel').forEach(panel => {
            panel.classList.remove('esse-tabs-panel--active');
        });
        tabs.querySelectorAll('.esse-tabs-btn').forEach(btn => {
            btn.closest('.esse-tabs-nav-item')?.classList.remove('esse-tabs-nav-item--active');
        });

        document.getElementById(id)?.classList.add('esse-tabs-panel--active');
        tab.closest('.esse-tabs-nav-item')?.classList.add('esse-tabs-nav-item--active');
    });
})();
