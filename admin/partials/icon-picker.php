<?php
$extraScriptConfig = $extraScriptConfig ?? [];
$extraScriptFiles  = $extraScriptFiles ?? [];

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
<?php
$extraScriptConfig['admin-icon-picker-config'] = ['prefix' => $_prefix];
$extraScriptFiles[] = '/public/assets/js/admin-icon-picker.js';
?>
