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

    $.fn.tooltip = function (opt) {
        return this.each(function () {
            if (typeof opt === 'string') {
                const t = bootstrap.Tooltip.getInstance(this);
                if (t) t[opt]();
            } else {
                new bootstrap.Tooltip(this, opt || {});
            }
        });
    };
    $.fn.popover = function (opt) {
        return this.each(function () {
            if (typeof opt === 'string') {
                const p = bootstrap.Popover.getInstance(this);
                if (p) p[opt]();
            } else {
                new bootstrap.Popover(this, opt || {});
            }
        });
    };
    $.fn.dropdown = function (opt) {
        return this.each(function () {
            if (typeof opt === 'string') {
                const d = bootstrap.Dropdown.getInstance(this) || new bootstrap.Dropdown(this);
                d[opt]();
            } else {
                new bootstrap.Dropdown(this, opt || {});
            }
        });
    };

    $('#content').summernote({
        lang: 'de-DE',
        height: 400,
        placeholder: 'Seiteninhalt ...',
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['table', ['table']],
            ['insert', ['link', 'picture', 'media', 'widget', 'hr']],
            ['view', ['fullscreen', 'codeview']],
        ],
        buttons: {
            media: window.EsseMediaButton,
            widget: window.EsseShortcodeButton,
        },
        callbacks: {
            onImageUpload: function (files) {
                const fd = new FormData();
                fd.append('file', files[0]);
                fd.append('_csrf', config.csrf || '');
                fetch('/admin/files/upload', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.url) {
                            $('#content').summernote('insertImage', data.url, files[0].name);
                        } else {
                            alert(data.error || 'Upload fehlgeschlagen.');
                        }
                    })
                    .catch(() => alert('Upload fehlgeschlagen.'));
            },
            onInit: function () {
                window.EsseShortcode && window.EsseShortcode.hydrate();
            },
        },
    });

    // Summernote (diese bs5-Variante) kennt keinen "onClick"-Callback — Klicks auf Bausteine
    // im Editor daher per nativem Listener auf dem erzeugten .note-editable-Element abfangen.
    document.querySelector('.note-editable')?.addEventListener('click', function (e) {
        const token = e.target.closest('.esse-shortcode-token');
        if (!token || !window.EsseShortcode) return;

        window.EsseShortcode.open(function (code, tokenHtml) {
            token.outerHTML = tokenHtml;
        }, token.getAttribute('data-shortcode') || '');
    });

    // Vorschau-Bausteine vor dem Absenden zurück in reinen "[tag ...]"-Text wandeln,
    // damit gespeicherter Seiteninhalt unverändert bleibt (kein HTML der Vorschau).
    document.getElementById('content')?.closest('form')?.addEventListener('submit', function () {
        const contentField = document.getElementById('content');
        if (!contentField || !window.EsseShortcode) return;
        contentField.value = window.EsseShortcode.serialize($('#content').summernote('code'));
    });

    const toolbar = document.querySelector('.note-toolbar');
    if (!toolbar) return;

    toolbar.addEventListener('click', function (e) {
        const toggle = e.target.closest('.dropdown-toggle');
        if (!toggle) return;

        const menu = toggle.parentElement.querySelector('.dropdown-menu');
        if (!menu) return;

        const willOpen = !menu.classList.contains('show');

        toolbar.querySelectorAll('.dropdown-menu.show').forEach(m => {
            m.classList.remove('show');
        });
        toolbar.querySelectorAll('.dropdown-toggle[aria-expanded=true]').forEach(t => {
            t.setAttribute('aria-expanded', 'false');
        });

        if (willOpen) {
            menu.classList.add('show');
            toggle.setAttribute('aria-expanded', 'true');
        }
    }, true);

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.note-toolbar')) {
            toolbar.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
            toolbar.querySelectorAll('.dropdown-toggle[aria-expanded=true]').forEach(t => {
                t.setAttribute('aria-expanded', 'false');
            });
        }
    });
})();
