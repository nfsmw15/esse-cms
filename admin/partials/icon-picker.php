<?php
// Read active icon pack prefix so JS can render previews immediately (no extra fetch needed)
$_ts       = \Esse\DB::table('settings');
$_pack     = \Esse\DB::value("SELECT `value` FROM `{$_ts}` WHERE `key` = 'icon_pack'") ?? 'bootstrap-icons';
$_packJson = ESSE_ROOT . '/public/vendor/' . basename($_pack) . '/iconpack.json';
$_prefix   = 'bi bi-';
if (file_exists($_packJson)) {
    $_meta   = json_decode(file_get_contents($_packJson), true);
    $_prefix = $_meta['prefix'] ?? 'bi bi-';
}
?>
<div class="modal fade" id="esseIconPickerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2 gap-2">
                <input type="text" id="esseIconSearch" class="form-control form-control-sm"
                       placeholder="Icons suchen…" autocomplete="off" spellcheck="false">
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <p id="esseIconStatus" class="text-center text-secondary py-4 mb-0">Lade Icon-Liste…</p>
                <div id="esseIconGrid" class="d-flex flex-wrap gap-1"></div>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    const ICON_PREFIX = <?= json_encode($_prefix) ?>;
    let _target = null;
    let _icons  = null; // string[] — loaded once on first modal open

    window.esseOpenIconPicker = function(inputEl) {
        _target = inputEl;
        document.getElementById('esseIconSearch').value = '';
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('esseIconPickerModal'));
        if (_icons) {
            renderGrid('');
        } else {
            setStatus('Lade Icon-Liste…');
            fetch('/admin/iconpacks/icons')
                .then(r => r.json())
                .then(data => { _icons = data.icons; renderGrid(''); });
        }
        modal.show();
        // Focus search after modal has opened
        document.getElementById('esseIconPickerModal').addEventListener('shown.bs.modal', function focus() {
            document.getElementById('esseIconSearch').focus();
            this.removeEventListener('shown.bs.modal', focus);
        });
    };

    window.esseUpdatePreview = function(inputEl) {
        const prev = document.querySelector('.esse-icon-preview[data-for="' + inputEl.id + '"]');
        if (!prev) return;
        const name = inputEl.value.trim();
        if (!name) {
            prev.innerHTML = '<i class="bi bi-grid-3x3-gap" style="opacity:.35;font-size:.95rem"></i>';
            prev.title = 'Icon wählen';
            return;
        }
        // Support full CSS class ("bi bi-house") and bare name ("house")
        const cls = name.includes(' ') ? name : ICON_PREFIX + name;
        prev.innerHTML = '<i class="' + cls + '" style="font-size:1rem"></i>';
        prev.title = name;
    };

    function setStatus(msg) {
        const s = document.getElementById('esseIconStatus');
        s.textContent = msg;
        s.style.display = '';
        document.getElementById('esseIconGrid').innerHTML = '';
    }

    function renderGrid(filter) {
        const grid = document.getElementById('esseIconGrid');
        const q    = filter.toLowerCase().trim();
        const list = _icons ? (q ? _icons.filter(n => n.includes(q)) : _icons) : [];

        if (!list.length) {
            setStatus(q ? 'Keine Icons gefunden.' : 'Keine Icons verfügbar.');
            return;
        }
        document.getElementById('esseIconStatus').style.display = 'none';
        grid.innerHTML = list.map(name =>
            '<button type="button" class="btn btn-outline-secondary p-1 esse-ipick-btn"' +
            ' style="width:48px;height:48px" data-name="' + name + '" title="' + name + '">' +
            '<i class="' + ICON_PREFIX + name + '" style="font-size:1.2rem;pointer-events:none"></i>' +
            '</button>'
        ).join('');
    }

    document.getElementById('esseIconSearch')?.addEventListener('input', function() {
        if (_icons) renderGrid(this.value);
    });

    document.getElementById('esseIconGrid')?.addEventListener('click', function(e) {
        const btn = e.target.closest('.esse-ipick-btn');
        if (!btn || !_target) return;
        _target.value = btn.dataset.name;
        _target.dispatchEvent(new Event('input', { bubbles: true }));
        bootstrap.Modal.getInstance(document.getElementById('esseIconPickerModal'))?.hide();
    });

    // Initialize previews for inputs that already have a value on page load
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('input[data-icon-preview]').forEach(function(input) {
            if (input.value) esseUpdatePreview(input);
        });
    });
})();
</script>
