(function () {
    'use strict';

    let activeCallback = null;
    let items = null;

    window.EsseShortcode = {
        // callback(code, tokenHtml) — existingCode (optional): raw "[tag attr="..."]" text to pre-fill (edit mode)
        open: function (callback, existingCode) {
            activeCallback = callback;
            const parsed = existingCode ? parseShortcode(existingCode) : null;

            const modalEl = document.getElementById('esseShortcodePickerModal');
            if (!modalEl) return;

            setStatus('Lade Widgets…');
            bootstrap.Modal.getOrCreateInstance(modalEl).show();

            ensureItemsLoaded(function () {
                if (parsed && items[parsed.tag]) {
                    renderEditCard(parsed.tag, parsed.attrs);
                } else {
                    renderList();
                }
            });
        },
        parse: parseShortcode,
        buildTokenHtml: buildTokenHtml,
        hydrate: hydrate,
        serialize: serialize,
    };

    function ensureItemsLoaded(cb) {
        if (items) { cb(); return; }
        fetch('/admin/shortcodes/list')
            .then(r => r.json())
            .then(data => {
                items = data.items && typeof data.items === 'object' ? data.items : {};
                cb();
            })
            .catch(() => { items = {}; cb(); });
    }

    function setStatus(message) {
        const status = document.getElementById('esseShortcodeStatus');
        const list = document.getElementById('esseShortcodeList');
        if (status) {
            status.textContent = message;
            status.classList.toggle('admin-hidden', !message);
        }
        if (list) list.innerHTML = '';
    }

    function renderList() {
        const list = document.getElementById('esseShortcodeList');
        if (!list) return;

        const tags = Object.keys(items || {});
        if (!tags.length) {
            setStatus('Keine Widgets verfügbar.');
            return;
        }

        setStatus('');
        list.innerHTML = tags.map(tag => renderCard(tag, items[tag], {}, 'Einfügen')).join('');
    }

    function renderEditCard(tag, presetAttrs) {
        const meta = items[tag] || {};
        const attributes = Array.isArray(meta.attributes) ? meta.attributes : [];
        const imagesAttr = attributes.find(a => a.type === 'images');
        const ids = imagesAttr && presetAttrs[imagesAttr.name]
            ? presetAttrs[imagesAttr.name].split(',').filter(Boolean)
            : [];

        if (!ids.length) {
            finishEditCard(tag, presetAttrs, {});
            return;
        }

        fetch('/admin/media/list?ids=' + ids.join(','))
            .then(r => r.json())
            .then(data => {
                const mediaById = {};
                (data.items || []).forEach(it => { mediaById[String(it.id)] = it; });
                finishEditCard(tag, presetAttrs, mediaById);
            })
            .catch(() => finishEditCard(tag, presetAttrs, {}));
    }

    function finishEditCard(tag, presetAttrs, mediaById) {
        const list = document.getElementById('esseShortcodeList');
        if (!list) return;
        setStatus('');
        list.innerHTML = renderCard(tag, items[tag], presetAttrs, 'Aktualisieren', mediaById);
    }

    function renderCard(tag, meta, presetAttrs, actionLabel, mediaById) {
        meta = meta || {};
        mediaById = mediaById || {};
        const attributes = Array.isArray(meta.attributes) ? meta.attributes : [];

        const inputs = attributes.map(attr => {
            const id = 'esseShortcodeAttr_' + tag + '_' + attr.name;
            const presetValue = presetAttrs[attr.name];

            if (attr.type === 'images') {
                const ids = presetValue ? presetValue.split(',').filter(Boolean) : [];
                const chipsHtml = ids.map(mid => renderImageChip(mediaById[mid] || { id: mid, url: '' })).join('');
                return '<div class="col-12">'
                    + '<label class="form-label small mb-1">' + escapeHtml(attr.label || attr.name) + '</label>'
                    + '<div class="esse-shortcode-image-chips" id="' + id + '_chips">' + chipsHtml + '</div>'
                    + '<button type="button" class="btn btn-secondary btn-sm esse-shortcode-image-add" data-target="' + id + '">'
                    + '<i class="bi bi-plus-lg"></i> Bild hinzufügen</button>'
                    + '<input type="hidden" id="' + id + '" data-attr="' + escapeAttr(attr.name) + '" value="' + escapeAttr(presetValue ?? attr.default ?? '') + '">'
                    + '</div>';
            }

            if (attr.type === 'select') {
                const options = Array.isArray(attr.options) ? attr.options : [];
                const current = presetValue ?? attr.default;
                const optionsHtml = options.map(opt =>
                    '<option value="' + escapeAttr(opt.value) + '"' + (opt.value === current ? ' selected' : '') + '>'
                    + escapeHtml(opt.label || opt.value) + '</option>'
                ).join('');
                return '<div class="col-auto">'
                    + '<label for="' + id + '" class="form-label small mb-1">' + escapeHtml(attr.label || attr.name) + '</label>'
                    + '<select class="form-select form-select-sm" id="' + id + '" data-attr="' + escapeAttr(attr.name) + '">'
                    + optionsHtml + '</select>'
                    + '</div>';
            }

            const type = attr.type === 'number' ? 'number' : 'text';
            return '<div class="col-auto">'
                + '<label for="' + id + '" class="form-label small mb-1">' + escapeHtml(attr.label || attr.name) + '</label>'
                + '<input type="' + type + '" class="form-control form-control-sm" id="' + id + '" '
                + 'data-attr="' + escapeAttr(attr.name) + '" value="' + escapeAttr(presetValue ?? attr.default ?? '') + '">'
                + '</div>';
        }).join('');

        return '<div class="list-group-item bg-dark border-secondary">'
            + '<div class="d-flex justify-content-between align-items-start gap-3">'
            + '<div>'
            + '<div class="fw-semibold">' + escapeHtml(meta.label || tag) + '</div>'
            + (meta.description ? '<div class="text-secondary small">' + escapeHtml(meta.description) + '</div>' : '')
            + '</div>'
            + '<button type="button" class="btn btn-primary btn-sm flex-shrink-0 esse-shortcode-insert" data-tag="' + escapeAttr(tag) + '">'
            + '<i class="bi bi-plus-lg"></i> ' + escapeHtml(actionLabel) + '</button>'
            + '</div>'
            + (inputs ? '<div class="row row-cols-auto g-2 mt-2">' + inputs + '</div>' : '')
            + '</div>';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
    function escapeAttr(s) {
        return escapeHtml(s);
    }

    function renderImageChip(file) {
        return '<span class="esse-shortcode-image-chip" data-id="' + escapeAttr(file.id) + '">'
            + '<img src="' + escapeAttr(file.url || '') + '" alt="">'
            + '<button type="button" class="esse-shortcode-image-chip-remove" aria-label="Entfernen">&times;</button>'
            + '</span>';
    }

    document.getElementById('esseShortcodeList')?.addEventListener('click', function (event) {
        const addBtn = event.target.closest('.esse-shortcode-image-add');
        if (addBtn) {
            const hiddenInput = document.getElementById(addBtn.dataset.target);
            const chips = document.getElementById(addBtn.dataset.target + '_chips');
            if (!hiddenInput || !chips || !window.EsseMedia) return;

            window.EsseMedia.open(function (file) {
                const ids = hiddenInput.value ? hiddenInput.value.split(',').filter(Boolean) : [];
                if (file.id && !ids.includes(String(file.id))) {
                    ids.push(String(file.id));
                    hiddenInput.value = ids.join(',');
                    chips.insertAdjacentHTML('beforeend', renderImageChip(file));
                }
            }, { type: 'image', warnPrivate: true });
            return;
        }

        const removeBtn = event.target.closest('.esse-shortcode-image-chip-remove');
        if (removeBtn) {
            const chip = removeBtn.closest('.esse-shortcode-image-chip');
            const wrapper = chip?.closest('.col-12');
            const hiddenInput = wrapper?.querySelector('input[type="hidden"][data-attr]');
            if (chip && hiddenInput) {
                const removedId = chip.dataset.id;
                const ids = hiddenInput.value.split(',').filter(id => id && id !== removedId);
                hiddenInput.value = ids.join(',');
                chip.remove();
            }
            return;
        }
    });

    document.getElementById('esseShortcodeList')?.addEventListener('click', function (event) {
        const btn = event.target.closest('.esse-shortcode-insert');
        if (!btn || !activeCallback) return;

        const tag = btn.dataset.tag;
        const item = btn.closest('.list-group-item');
        const meta = (items && items[tag]) || {};
        const attrs = {};
        const mediaById = {};

        item?.querySelectorAll('[data-attr]').forEach(input => {
            const value = input.value.trim();
            if (value !== '') attrs[input.dataset.attr] = value;
        });
        item?.querySelectorAll('.esse-shortcode-image-chip').forEach(chip => {
            const img = chip.querySelector('img');
            if (chip.dataset.id) mediaById[chip.dataset.id] = { id: chip.dataset.id, url: img ? img.getAttribute('src') : '' };
        });

        const code = buildCode(tag, attrs, meta);
        const tokenHtml = buildTokenHtml(tag, attrs, mediaById);
        activeCallback(code, tokenHtml);

        bootstrap.Modal.getInstance(document.getElementById('esseShortcodePickerModal'))?.hide();
    });

    // ── Shared: parse / build / hydrate / serialize ───────────────────────────

    function parseAttrs(attrsStr) {
        const attrs = {};
        const re = /([\w-]+)="([^"]*)"/g;
        let m;
        while ((m = re.exec(attrsStr || ''))) attrs[m[1]] = m[2];
        return attrs;
    }

    function parseShortcode(code) {
        const m = /^\[(\w+)((?:\s+[\w-]+="[^"]*")*)\s*\]$/.exec((code || '').trim());
        if (!m) return null;
        return { tag: m[1], attrs: parseAttrs(m[2]) };
    }

    function buildCode(tag, attrs, meta) {
        const attributes = Array.isArray(meta && meta.attributes) ? meta.attributes : [];
        const names = attributes.map(a => a.name);
        const ordered = names.filter(n => attrs[n] !== undefined && attrs[n] !== '');
        Object.keys(attrs).forEach(n => {
            if (!names.includes(n) && attrs[n] !== undefined && attrs[n] !== '') ordered.push(n);
        });
        const parts = ordered.map(n => n + '="' + String(attrs[n]).replace(/"/g, '&quot;') + '"');
        return '[' + tag + (parts.length ? ' ' + parts.join(' ') : '') + ']';
    }

    // Builds the non-editable preview block shown in Summernote instead of raw "[tag ...]" text.
    function buildTokenHtml(tag, attrs, mediaById) {
        mediaById = mediaById || {};
        const meta = (items && items[tag]) || {};
        const attributes = Array.isArray(meta.attributes) ? meta.attributes : [];
        const code = buildCode(tag, attrs, meta);

        let thumbsHtml = '';
        let imageCount = 0;
        const metaParts = [];

        attributes.forEach(attr => {
            const value = attrs[attr.name];

            if (attr.type === 'images') {
                const ids = (value || '').split(',').filter(Boolean);
                imageCount = ids.length;
                const shown = ids.slice(0, 5);
                thumbsHtml = shown.map(id => {
                    const media = mediaById[id];
                    return media && media.url
                        ? '<img src="' + escapeAttr(media.url) + '" alt="">'
                        : '<span class="esse-shortcode-token-thumb-missing"><i class="bi bi-image"></i></span>';
                }).join('');
                if (ids.length > shown.length) {
                    thumbsHtml += '<span class="esse-shortcode-token-more">+' + (ids.length - shown.length) + '</span>';
                }
                return;
            }

            if (value === undefined || value === '') return;
            let display = value;
            if (attr.type === 'select') {
                const opt = (attr.options || []).find(o => o.value === value);
                if (opt) display = opt.label;
            }
            const shortLabel = (attr.label || attr.name).split('(')[0].trim();
            metaParts.push(shortLabel + ': ' + display);
        });

        if (imageCount) metaParts.unshift(imageCount + (imageCount === 1 ? ' Bild' : ' Bilder'));

        return '<span class="esse-shortcode-token" contenteditable="false" data-shortcode="' + escapeAttr(code) + '">'
            + (thumbsHtml ? '<span class="esse-shortcode-token-thumbs">' + thumbsHtml + '</span>' : '')
            + '<span class="esse-shortcode-token-body">'
            + '<span class="esse-shortcode-token-label"><i class="bi bi-puzzle"></i> ' + escapeHtml(meta.label || tag) + '</span>'
            + (metaParts.length ? '<span class="esse-shortcode-token-meta">' + escapeHtml(metaParts.join(' · ')) + '</span>' : '')
            + '</span></span>';
    }

    // Scans the editor's current content for raw "[tag ...]" text matching a registered
    // shortcode and upgrades it in place to the preview token (used right after loading a
    // page with existing shortcode text into Summernote).
    function hydrate() {
        if (!window.jQuery || !jQuery.fn.summernote || !document.getElementById('content')) return;

        const html = jQuery('#content').summernote('code');
        const quickCheck = /\[\w+(?:\s+[\w-]+="[^"]*")*\s*\]/;
        if (!quickCheck.test(html)) return;

        ensureItemsLoaded(function () {
            const matches = [];
            const scanRe = /\[(\w+)((?:\s+[\w-]+="[^"]*")*)\s*\]/g;
            let m;
            while ((m = scanRe.exec(html))) {
                if (items[m[1]]) matches.push({ full: m[0], tag: m[1], attrs: parseAttrs(m[2]) });
            }
            if (!matches.length) return;

            const idsNeeded = new Set();
            matches.forEach(mt => {
                const meta = items[mt.tag] || {};
                (meta.attributes || []).forEach(a => {
                    if (a.type === 'images' && mt.attrs[a.name]) {
                        mt.attrs[a.name].split(',').forEach(id => id && idsNeeded.add(id));
                    }
                });
            });

            const finish = function (mediaById) {
                let newHtml = html;
                matches.forEach(mt => {
                    newHtml = newHtml.split(mt.full).join(buildTokenHtml(mt.tag, mt.attrs, mediaById));
                });
                jQuery('#content').summernote('code', newHtml);
            };

            if (idsNeeded.size) {
                fetch('/admin/media/list?ids=' + Array.from(idsNeeded).join(','))
                    .then(r => r.json())
                    .then(data => {
                        const mediaById = {};
                        (data.items || []).forEach(it => { mediaById[String(it.id)] = it; });
                        finish(mediaById);
                    })
                    .catch(() => finish({}));
            } else {
                finish({});
            }
        });
    }

    // Converts preview tokens back to plain "[tag ...]" text — called right before the
    // page form is submitted, so the stored content stays plain shortcode text.
    function serialize(html) {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        wrapper.querySelectorAll('.esse-shortcode-token').forEach(function (el) {
            el.replaceWith(document.createTextNode(el.getAttribute('data-shortcode') || ''));
        });
        return wrapper.innerHTML;
    }
})();
