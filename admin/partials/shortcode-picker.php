<?php
$extraScriptConfig = $extraScriptConfig ?? [];
$extraScriptFiles  = $extraScriptFiles ?? [];

$extraScriptConfig['admin-shortcode-picker-config'] = ['csrf' => \Esse\Auth::csrfToken()];
$extraScriptFiles[] = '/public/assets/js/shortcode-picker.js';
?>
<div class="modal fade" id="esseShortcodePickerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">Widget einfügen</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="esseShortcodeStatus" class="text-center text-secondary py-4 mb-0">Lade Widgets…</p>
                <div id="esseShortcodeList" class="list-group"></div>
            </div>
        </div>
    </div>
</div>
