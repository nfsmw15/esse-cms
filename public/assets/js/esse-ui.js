(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        const close = event.target.closest('[data-esse-alert-close]');
        if (close) {
            close.closest('.esse-alert')?.remove();
            return;
        }

        const tab = event.target.closest('[data-esse-tab]');
        if (tab) {
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
            return;
        }

        const prev = event.target.closest('[data-esse-carousel-prev]');
        if (prev) {
            const root = prev.closest('.esse-carousel');
            if (root) esseCarouselGoto(root, esseCarouselIndex(root) - 1);
            return;
        }

        const next = event.target.closest('[data-esse-carousel-next]');
        if (next) {
            const root = next.closest('.esse-carousel');
            if (root) esseCarouselGoto(root, esseCarouselIndex(root) + 1);
            return;
        }

        const dot = event.target.closest('[data-esse-carousel-goto]');
        if (dot) {
            const root = dot.closest('.esse-carousel');
            const idx = parseInt(dot.getAttribute('data-esse-carousel-goto'), 10);
            if (root && !isNaN(idx)) esseCarouselGoto(root, idx);
            return;
        }
    });

    // ── Carousel ─────────────────────────────────────────────────────────────

    function esseCarouselIndex(root) {
        const active = root.querySelector('.esse-carousel-slide--active');
        return active ? parseInt(active.getAttribute('data-esse-carousel-slide'), 10) || 0 : 0;
    }

    function esseCarouselGoto(root, index) {
        const slides = root.querySelectorAll('.esse-carousel-slide');
        const dots   = root.querySelectorAll('.esse-carousel-dot');
        if (!slides.length) return;

        const next = ((index % slides.length) + slides.length) % slides.length;

        slides.forEach(s => s.classList.remove('esse-carousel-slide--active'));
        dots.forEach(d => d.classList.remove('esse-carousel-dot--active'));

        slides[next]?.classList.add('esse-carousel-slide--active');
        dots[next]?.classList.add('esse-carousel-dot--active');
    }

    function esseCarouselInit() {
        const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        document.querySelectorAll('.esse-carousel[data-esse-carousel-interval]').forEach(root => {
            if (root.dataset.esseCarouselInitialized) return;
            root.dataset.esseCarouselInitialized = '1';

            const interval = parseInt(root.getAttribute('data-esse-carousel-interval'), 10);
            if (!interval || interval <= 0 || reducedMotion) return;

            let timer = setInterval(() => esseCarouselGoto(root, esseCarouselIndex(root) + 1), interval);

            root.addEventListener('mouseenter', () => { clearInterval(timer); });
            root.addEventListener('mouseleave', () => {
                timer = setInterval(() => esseCarouselGoto(root, esseCarouselIndex(root) + 1), interval);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', esseCarouselInit);
    } else {
        esseCarouselInit();
    }
})();
