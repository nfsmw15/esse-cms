(function () {
    'use strict';

    const configEl = document.getElementById('admin-icon-picker-config');
    let config = { prefix: 'bi bi-' };
    if (configEl) {
        try {
            config = Object.assign(config, JSON.parse(configEl.textContent || '{}'));
        } catch (e) {
            config = { prefix: 'bi bi-' };
        }
    }

    const iconPrefix = config.prefix || 'bi bi-';
    let target = null;
    let icons = null;

    window.esseOpenIconPicker = function (inputEl) {
        target = inputEl;

        const search = document.getElementById('esseIconSearch');
        if (search) search.value = '';

        const modalEl = document.getElementById('esseIconPickerModal');
        if (!modalEl) return;

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        if (icons) {
            renderGrid('');
        } else {
            setStatus('Lade Icon-Liste...');
            fetch('/admin/iconpacks/icons')
                .then(r => r.json())
                .then(data => {
                    icons = Array.isArray(data.icons) ? data.icons : [];
                    renderGrid('');
                })
                .catch(() => setStatus('Icon-Liste konnte nicht geladen werden.'));
        }

        modal.show();
        modalEl.addEventListener('shown.bs.modal', function focus() {
            document.getElementById('esseIconSearch')?.focus();
            modalEl.removeEventListener('shown.bs.modal', focus);
        });
    };

    window.esseUpdatePreview = function (inputEl) {
        const prev = document.querySelector('.esse-icon-preview[data-for="' + inputEl.id + '"]');
        if (!prev) return;

        const name = inputEl.value.trim();
        // DOM-Elemente statt innerHTML bauen: className ist eine reine String-Zuweisung, kein
        // HTML-Parsing — ein eingegebener Wert wie '" onmouseover="..."' kann damit nicht aus dem
        // class-Attribut ausbrechen, selbst wenn der Eingabewert (noch) nicht serverseitig
        // validiert wurde (das passiert erst beim Speichern, nicht waehrend der Live-Vorschau).
        prev.replaceChildren();
        const icon = document.createElement('i');
        if (!name) {
            icon.className = 'bi bi-grid-3x3-gap esse-icon-muted';
            prev.title = 'Icon wählen';
        } else {
            const cls = name.includes(' ') ? name : iconPrefix + name;
            icon.className = cls + ' esse-icon-preview-glyph';
            prev.title = name;
        }
        prev.appendChild(icon);
    };

    function setStatus(message) {
        const status = document.getElementById('esseIconStatus');
        const grid = document.getElementById('esseIconGrid');
        if (status) {
            status.textContent = message;
            status.classList.remove('admin-hidden');
        }
        if (grid) grid.innerHTML = '';
    }

    const germanIconMap = {
        haus: ['house'], zuhause: ['house'], 'gebäude': ['building', 'house'],
        wohnung: ['house'], 'büro': ['building'],
        einstellung: ['gear', 'sliders'], einstellungen: ['gear', 'sliders'],
        konfiguration: ['gear', 'wrench'], zahnrad: ['gear'],
        werkzeug: ['wrench', 'tools'], schraubenzieher: ['wrench'],
        hammer: ['hammer'],
        kreis: ['circle'], punkt: ['circle', 'dot', 'record'],
        kugel: ['circle'], ring: ['circle'],
        quadrat: ['square'], rechteck: ['rectangle'],
        dreieck: ['triangle'], raute: ['diamond'],
        stern: ['star'], achteck: ['octagon'],
        linie: ['dash', 'hr'], strich: ['dash'],
        pfeil: ['arrow'], 'zurück': ['arrow-left'],
        weiter: ['arrow-right'], vor: ['arrow-right'],
        oben: ['arrow-up', 'chevron-up'], unten: ['arrow-down', 'chevron-down'],
        links: ['arrow-left', 'chevron-left'], rechts: ['arrow-right', 'chevron-right'],
        hoch: ['arrow-up'], runter: ['arrow-down'],
        doppelpfeil: ['arrows'], expandieren: ['arrows-fullscreen'],
        ausklappen: ['chevron-down', 'caret-down'],
        einklappen: ['chevron-up', 'caret-up'],
        nutzer: ['person'], benutzer: ['person'],
        gruppe: ['people'], personen: ['people'], team: ['people'],
        autor: ['person', 'pencil'], kontakt: ['person-lines-fill'],
        kontakte: ['people'],
        kalender: ['calendar'], datum: ['calendar'], termin: ['calendar'],
        uhr: ['clock'], zeit: ['clock'], stoppuhr: ['stopwatch'],
        sanduhr: ['hourglass'], wecker: ['alarm'], timer: ['stopwatch'],
        suche: ['search'], lupe: ['search', 'zoom'],
        ausblenden: ['eye-slash'], anzeigen: ['eye'], sichtbar: ['eye'],
        vollbild: ['fullscreen'], zoom: ['zoom'],
        bild: ['image'], foto: ['image', 'camera'], kamera: ['camera'],
        video: ['video', 'camera-video'], film: ['film', 'camera-video'],
        musik: ['music'], ton: ['volume-up', 'music'],
        abspielen: ['play'], pause: ['pause'], stopp: ['stop'],
        lautsprecher: ['speaker', 'volume'], 'lautstärke': ['volume'],
        stumm: ['volume-mute'], mikrofon: ['mic'],
        datei: ['file'], ordner: ['folder'],
        dokument: ['file-text'], textdatei: ['file-text'],
        pdf: ['file-pdf'], 'bild datei': ['file-image'],
        zip: ['file-zip'], anhang: ['paperclip'],
        'büroklammer': ['paperclip'],
        'löschen': ['trash', 'x'], papierkorb: ['trash'],
        bearbeiten: ['pencil'], stift: ['pencil'],
        'hinzufügen': ['plus'], neu: ['plus'],
        'schließen': ['x'], abbrechen: ['x'],
        fertig: ['check'], 'häkchen': ['check'],
        speichern: ['floppy'], kopieren: ['copy', 'files'],
        ausschneiden: ['scissors'], 'einfügen': ['clipboard'],
        'rückgängig': ['arrow-counterclockwise'],
        wiederholen: ['arrow-clockwise'],
        aktualisieren: ['arrow-repeat'], synchronisieren: ['arrow-repeat'],
        laden: ['arrow-repeat'], neuladen: ['arrow-clockwise'],
        drucken: ['printer'], teilen: ['share'],
        download: ['download'], upload: ['upload'],
        exportieren: ['box-arrow-up'], importieren: ['box-arrow-in-down'],
        senden: ['send'], weiterleiten: ['forward'],
        warnung: ['exclamation', 'triangle'], achtung: ['exclamation', 'triangle'],
        fehler: ['x-circle', 'exclamation'], info: ['info'],
        erfolg: ['check-circle'], ok: ['check-circle'],
        gesperrt: ['lock', 'slash'], freigegeben: ['unlock'],
        aktiv: ['check-circle'], inaktiv: ['dash-circle'],
        warten: ['hourglass', 'three-dots'], abgeschlossen: ['check-all'],
        wichtig: ['exclamation', 'star'], dringend: ['exclamation-triangle'],
        email: ['envelope'], mail: ['envelope'], brief: ['envelope'],
        nachricht: ['chat', 'envelope'], nachrichten: ['chat', 'inbox'],
        kommentar: ['chat'], antwort: ['reply'],
        benachrichtigung: ['bell'], glocke: ['bell'],
        telefon: ['telephone'], handy: ['phone'],
        link: ['link'], extern: ['box-arrow-up-right'],
        'menü': ['list'], navigation: ['compass'],
        liste: ['list'], raster: ['grid'], kacheln: ['grid'],
        sortieren: ['sort'], filter: ['filter', 'funnel'],
        seite: ['file-earmark'], artikel: ['file-text', 'newspaper'],
        kategorie: ['collection', 'folder'], archiv: ['archive'],
        anmelden: ['box-arrow-in-right'], einloggen: ['box-arrow-in-right'],
        abmelden: ['box-arrow-right'], ausloggen: ['box-arrow-right'],
        registrieren: ['person-plus'],
        profil: ['person-circle'], konto: ['person-gear'],
        passwort: ['key', 'lock'],
        sicherheit: ['shield'], berechtigung: ['shield-check'],
        neuigkeit: ['newspaper'], news: ['newspaper'],
        buch: ['book'], anleitung: ['book'],
        notiz: ['sticky', 'journal'], notizbuch: ['journal'],
        klemmbrett: ['clipboard'],
        zitat: ['quote'], 'aufzählung': ['list-ul'],
        dashboard: ['speedometer'], bericht: ['file-earmark-bar-chart'],
        statistik: ['bar-chart', 'graph'], diagramm: ['bar-chart', 'graph'],
        analyse: ['graph-up'], tabelle: ['table'],
        computer: ['pc', 'laptop'], laptop: ['laptop'],
        tablet: ['tablet'], smartphone: ['phone'],
        bildschirm: ['display', 'monitor'], server: ['server', 'hdd'],
        datenbank: ['database'], cloud: ['cloud'],
        code: ['code', 'braces'], terminal: ['terminal'],
        wlan: ['wifi'], netzwerk: ['wifi', 'ethernet'],
        batterie: ['battery'], usb: ['usb-drive'],
        plugin: ['puzzle'], erweiterung: ['puzzle'],
        farbe: ['palette'], design: ['palette'],
        hilfe: ['question-circle'], faq: ['question-square'],
        welt: ['globe'], sprache: ['translate', 'globe'],
        favorit: ['star', 'heart'], herz: ['heart'],
        'gefällt': ['heart', 'hand-thumbs'], bewertung: ['star'],
        flagge: ['flag'], etikett: ['tag'], preis: ['tag'],
        schloss: ['lock'], sperren: ['lock'], 'schlüssel': ['key'],
        einkauf: ['cart', 'bag'], warenkorb: ['cart'],
        geld: ['currency', 'cash', 'coin'], euro: ['currency-euro'],
        dollar: ['currency-dollar'], rechnung: ['receipt'],
        gutschein: ['gift'], geschenk: ['gift'],
        produkt: ['box'], paket: ['box'],
        lieferung: ['truck'], versand: ['truck'],
        karte: ['map', 'credit-card'], landkarte: ['map'],
        standort: ['geo', 'pin-map'], ort: ['geo'],
        sonne: ['sun'], mond: ['moon'], nacht: ['moon'],
        wolke: ['cloud'], regen: ['cloud-rain'],
        blitz: ['lightning'],
    };

    function searchIcons(query) {
        if (!icons) return [];
        const q = query.toLowerCase().trim();
        if (!q) return icons;

        const byName = icons.filter(name => name.includes(q));
        const patterns = new Set();
        for (const [de, names] of Object.entries(germanIconMap)) {
            if (de.startsWith(q) || de.includes(q)) {
                names.forEach(name => patterns.add(name));
            }
        }

        if (!patterns.size) return byName;

        const seen = new Set(byName);
        const expanded = icons.filter(name =>
            !seen.has(name) && Array.from(patterns).some(pattern => name.includes(pattern))
        );

        return byName.concat(expanded);
    }

    function renderGrid(filter) {
        const grid = document.getElementById('esseIconGrid');
        const status = document.getElementById('esseIconStatus');
        if (!grid) return;

        const list = searchIcons(filter);
        if (!list.length) {
            setStatus(filter ? 'Keine Icons gefunden.' : 'Keine Icons verfügbar.');
            return;
        }

        if (status) status.classList.add('admin-hidden');
        grid.innerHTML = list.map(name =>
            '<button type="button" class="btn btn-outline-secondary p-1 esse-ipick-btn" data-name="' + name + '" title="' + name + '">' +
            '<i class="' + iconPrefix + name + ' esse-ipick-glyph"></i>' +
            '</button>'
        ).join('');
    }

    document.getElementById('esseIconSearch')?.addEventListener('input', function () {
        if (icons) renderGrid(this.value);
    });

    document.getElementById('esseIconGrid')?.addEventListener('click', function (event) {
        const btn = event.target.closest('.esse-ipick-btn');
        if (!btn || !target) return;
        target.value = btn.dataset.name;
        target.dispatchEvent(new Event('input', { bubbles: true }));
        bootstrap.Modal.getInstance(document.getElementById('esseIconPickerModal'))?.hide();
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[data-icon-preview]').forEach(function (input) {
            if (input.value) window.esseUpdatePreview(input);
        });
    });
})();
