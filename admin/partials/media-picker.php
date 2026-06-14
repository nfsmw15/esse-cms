<?php
$extraScriptConfig = $extraScriptConfig ?? [];
$extraScriptFiles  = $extraScriptFiles ?? [];

$extraScriptConfig['admin-media-picker-config'] = ['csrf' => \Esse\Auth::csrfToken()];
$extraScriptFiles[] = '/public/assets/js/media-picker.js';
?>
<div class="modal fade" id="esseMediaPickerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary gap-2">
                <h5 class="modal-title">Mediathek</h5>
                <input type="text" id="esseMediaSearch" class="form-control form-control-sm ms-auto esse-media-search"
                       placeholder="Suche..." autocomplete="off">
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" id="esseMediaTabBrowse" data-bs-toggle="tab" data-bs-target="#esseMediaPaneBrowse" type="button">
                            Vorhandene Dateien
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="esseMediaTabUpload" data-bs-toggle="tab" data-bs-target="#esseMediaPaneUpload" type="button">
                            Hochladen
                        </button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="esseMediaPaneBrowse">
                        <p id="esseMediaStatus" class="text-center text-secondary py-4 mb-0">Lade Mediathek…</p>
                        <div id="esseMediaGrid" class="media-grid"></div>
                    </div>
                    <div class="tab-pane fade" id="esseMediaPaneUpload">
                        <div class="mb-3">
                            <input type="file" id="esseMediaUploadFile" class="form-control" accept="image/*">
                            <div class="form-text">Erlaubt: jpg, jpeg, png, gif, webp (max. 10 MB)</div>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm" id="esseMediaUploadBtn">
                            <i class="bi bi-upload"></i> Hochladen &amp; einfügen
                        </button>
                        <p id="esseMediaUploadStatus" class="text-secondary small mt-2 mb-0"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
