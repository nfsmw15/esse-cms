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

    // German keyword → English icon name patterns
    const DE_MAP = {
        // Haus & Gebäude
        'haus': ['house'], 'zuhause': ['house'], 'gebäude': ['building','house'],
        'wohnung': ['house'], 'büro': ['building'],

        // Einstellungen & Werkzeuge
        'einstellung': ['gear','sliders'], 'einstellungen': ['gear','sliders'],
        'konfiguration': ['gear','wrench'], 'zahnrad': ['gear'],
        'werkzeug': ['wrench','tools'], 'schraubenzieher': ['wrench'],
        'hammer': ['hammer'],

        // Geometrische Formen
        'kreis': ['circle'], 'punkt': ['circle','dot','record'],
        'kugel': ['circle'], 'ring': ['circle'],
        'quadrat': ['square'], 'rechteck': ['rectangle'],
        'dreieck': ['triangle'], 'raute': ['diamond'],
        'stern': ['star'], 'achteck': ['octagon'],
        'linie': ['dash','hr'], 'strich': ['dash'],

        // Pfeile & Richtungen
        'pfeil': ['arrow'], 'zurück': ['arrow-left'],
        'weiter': ['arrow-right'], 'vor': ['arrow-right'],
        'oben': ['arrow-up','chevron-up'], 'unten': ['arrow-down','chevron-down'],
        'links': ['arrow-left','chevron-left'], 'rechts': ['arrow-right','chevron-right'],
        'hoch': ['arrow-up'], 'runter': ['arrow-down'],
        'doppelpfeil': ['arrows'], 'expandieren': ['arrows-fullscreen'],
        'ausklappen': ['chevron-down','caret-down'],
        'einklappen': ['chevron-up','caret-up'],

        // Personen & Nutzer
        'nutzer': ['person'], 'benutzer': ['person'],
        'gruppe': ['people'], 'personen': ['people'], 'team': ['people'],
        'autor': ['person','pencil'], 'kontakt': ['person-lines-fill'],
        'kontakte': ['people'],

        // Zeit & Kalender
        'kalender': ['calendar'], 'datum': ['calendar'], 'termin': ['calendar'],
        'uhr': ['clock'], 'zeit': ['clock'], 'stoppuhr': ['stopwatch'],
        'sanduhr': ['hourglass'], 'wecker': ['alarm'], 'timer': ['stopwatch'],

        // Suche & Ansicht
        'suche': ['search'], 'lupe': ['search','zoom'],
        'ausblenden': ['eye-slash'], 'anzeigen': ['eye'], 'sichtbar': ['eye'],
        'vollbild': ['fullscreen'], 'zoom': ['zoom'],

        // Medien
        'bild': ['image'], 'foto': ['image','camera'], 'kamera': ['camera'],
        'video': ['video','camera-video'], 'film': ['film','camera-video'],
        'musik': ['music'], 'ton': ['volume-up','music'],
        'abspielen': ['play'], 'pause': ['pause'], 'stopp': ['stop'],
        'lautsprecher': ['speaker','volume'], 'lautstärke': ['volume'],
        'stumm': ['volume-mute'], 'mikrofon': ['mic'],

        // Dateien & Ordner
        'datei': ['file'], 'ordner': ['folder'],
        'dokument': ['file-text'], 'textdatei': ['file-text'],
        'pdf': ['file-pdf'], 'bild datei': ['file-image'],
        'zip': ['file-zip'], 'anhang': ['paperclip'],
        'büroklammer': ['paperclip'],

        // Aktionen
        'löschen': ['trash','x'], 'papierkorb': ['trash'],
        'bearbeiten': ['pencil'], 'stift': ['pencil'],
        'hinzufügen': ['plus'], 'neu': ['plus'],
        'schließen': ['x'], 'abbrechen': ['x'],
        'fertig': ['check'], 'häkchen': ['check'],
        'speichern': ['floppy'], 'kopieren': ['copy','files'],
        'ausschneiden': ['scissors'], 'einfügen': ['clipboard'],
        'rückgängig': ['arrow-counterclockwise'],
        'wiederholen': ['arrow-clockwise'],
        'aktualisieren': ['arrow-repeat'], 'synchronisieren': ['arrow-repeat'],
        'laden': ['arrow-repeat'], 'neuladen': ['arrow-clockwise'],
        'drucken': ['printer'], 'teilen': ['share'],
        'download': ['download'], 'upload': ['upload'],
        'exportieren': ['box-arrow-up'], 'importieren': ['box-arrow-in-down'],
        'senden': ['send'], 'weiterleiten': ['forward'],

        // Status & Feedback
        'warnung': ['exclamation','triangle'], 'achtung': ['exclamation','triangle'],
        'fehler': ['x-circle','exclamation'], 'info': ['info'],
        'erfolg': ['check-circle'], 'ok': ['check-circle'],
        'gesperrt': ['lock','slash'], 'freigegeben': ['unlock'],
        'aktiv': ['check-circle'], 'inaktiv': ['dash-circle'],
        'warten': ['hourglass','three-dots'], 'abgeschlossen': ['check-all'],
        'wichtig': ['exclamation','star'], 'dringend': ['exclamation-triangle'],

        // Kommunikation
        'email': ['envelope'], 'mail': ['envelope'], 'brief': ['envelope'],
        'nachricht': ['chat','envelope'], 'nachrichten': ['chat','inbox'],
        'kommentar': ['chat'], 'antwort': ['reply'],
        'benachrichtigung': ['bell'], 'glocke': ['bell'],
        'telefon': ['telephone'], 'handy': ['phone'],

        // Navigation & Struktur
        'link': ['link'], 'extern': ['box-arrow-up-right'],
        'menü': ['list'], 'navigation': ['compass'],
        'liste': ['list'], 'raster': ['grid'], 'kacheln': ['grid'],
        'sortieren': ['sort'], 'filter': ['filter','funnel'],
        'seite': ['file-earmark'], 'artikel': ['file-text','newspaper'],
        'kategorie': ['collection','folder'], 'archiv': ['archive'],

        // Login & Nutzerbereich
        'anmelden': ['box-arrow-in-right'], 'einloggen': ['box-arrow-in-right'],
        'abmelden': ['box-arrow-right'], 'ausloggen': ['box-arrow-right'],
        'registrieren': ['person-plus'],
        'profil': ['person-circle'], 'konto': ['person-gear'],
        'passwort': ['key','lock'],
        'sicherheit': ['shield'], 'berechtigung': ['shield-check'],

        // Inhalt & Medien
        'neuigkeit': ['newspaper'], 'news': ['newspaper'],
        'buch': ['book'], 'anleitung': ['book'],
        'notiz': ['sticky','journal'], 'notizbuch': ['journal'],
        'klemmbrett': ['clipboard'],
        'zitat': ['quote'], 'aufzählung': ['list-ul'],

        // Analyse & Berichte
        'dashboard': ['speedometer'], 'bericht': ['file-earmark-bar-chart'],
        'statistik': ['bar-chart','graph'], 'diagramm': ['bar-chart','graph'],
        'analyse': ['graph-up'], 'tabelle': ['table'],

        // Technik
        'computer': ['pc','laptop'], 'laptop': ['laptop'],
        'tablet': ['tablet'], 'smartphone': ['phone'],
        'bildschirm': ['display','monitor'], 'server': ['server','hdd'],
        'datenbank': ['database'], 'cloud': ['cloud'],
        'code': ['code','braces'], 'terminal': ['terminal'],
        'wlan': ['wifi'], 'netzwerk': ['wifi','ethernet'],
        'batterie': ['battery'], 'usb': ['usb-drive'],
        'mikrofon': ['mic'],

        // Einstellungen & System
        'plugin': ['puzzle'], 'erweiterung': ['puzzle'],
        'farbe': ['palette'], 'design': ['palette'],
        'hilfe': ['question-circle'], 'faq': ['question-square'],
        'welt': ['globe'], 'sprache': ['translate','globe'],

        // Favoriten & Bewertung
        'favorit': ['star','heart'], 'herz': ['heart'],
        'gefällt': ['heart','hand-thumbs'], 'bewertung': ['star'],
        'flagge': ['flag'], 'etikett': ['tag'], 'preis': ['tag'],

        // Schloss & Sicherheit
        'schloss': ['lock'], 'sperren': ['lock'], 'schlüssel': ['key'],

        // Shopping & Geld
        'einkauf': ['cart','bag'], 'warenkorb': ['cart'],
        'geld': ['currency','cash','coin'], 'euro': ['currency-euro'],
        'dollar': ['currency-dollar'], 'rechnung': ['receipt'],
        'gutschein': ['gift'], 'geschenk': ['gift'],
        'produkt': ['box'], 'paket': ['box'],
        'lieferung': ['truck'], 'versand': ['truck'],

        // Geografie & Natur
        'karte': ['map','credit-card'], 'landkarte': ['map'],
        'standort': ['geo','pin-map'], 'ort': ['geo'],
        'sonne': ['sun'], 'mond': ['moon'], 'nacht': ['moon'],
        'wolke': ['cloud'], 'regen': ['cloud-rain'],
        'blitz': ['lightning'],
    };

    function searchIcons(q) {
        if (!_icons) return [];
        if (!q) return _icons;

        // Direct English name match
        const byName = _icons.filter(n => n.includes(q));

        // German keyword expansion: find all DE keys that contain the query
        const patterns = new Set();
        for (const [de, pats] of Object.entries(DE_MAP)) {
            if (de.startsWith(q) || de.includes(q)) {
                pats.forEach(p => patterns.add(p));
            }
        }

        if (!patterns.size) return byName;

        const nameSet = new Set(byName);
        const extra   = _icons.filter(n =>
            !nameSet.has(n) && [...patterns].some(p => n.includes(p))
        );
        return [...byName, ...extra];
    }

    function renderGrid(filter) {
        const grid = document.getElementById('esseIconGrid');
        const q    = filter.toLowerCase().trim();
        const list = searchIcons(q);

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
