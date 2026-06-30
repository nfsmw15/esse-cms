// Live-Checkliste fuer Passwort-Anforderungen (Registrierung, Profil, Passwort-Reset).
// Bewusst ohne jede Abhaengigkeit von Theme-CSS (kein bi-*-Icon, keine text-success/-danger-
// Klasse) — Frontend-Themes sind laut README framework-agnostisch und laden nicht zwingend
// Bootstrap oder Bootstrap-Icons. Farbe/Symbol werden daher direkt per Inline-Style/Unicode
// gesetzt, das funktioniert unabhaengig vom aktiven Theme.
(function () {
    'use strict';

    function classesOf(password) {
        return {
            upper:   /[A-Z]/.test(password),
            lower:   /[a-z]/.test(password),
            digit:   /[0-9]/.test(password),
            special: /[^a-zA-Z0-9]/.test(password),
        };
    }

    // Spiegelt PasswordPolicy::hasLongSequential() — Laeufe von mehr als maxRun auf- oder
    // absteigenden Zeichen derselben Klasse. Richtungswechsel brechen den Lauf ab.
    function hasLongSequential(password, maxRun) {
        function classOf(ch) {
            if (/[a-z]/.test(ch)) return 'lower';
            if (/[A-Z]/.test(ch)) return 'upper';
            if (/[0-9]/.test(ch)) return 'digit';
            return null;
        }
        let run = 1, dir = 0;
        for (let i = 1; i < password.length; i++) {
            const prev = password[i - 1], cur = password[i];
            const sameClass = classOf(prev) !== null && classOf(prev) === classOf(cur);
            const delta = sameClass ? (cur.charCodeAt(0) - prev.charCodeAt(0)) : 0;
            const curDir = delta === 1 ? 1 : (delta === -1 ? -1 : 0);
            if (curDir !== 0 && curDir === dir) run++;
            else if (curDir !== 0) { run = 2; dir = curDir; }
            else { run = 1; dir = 0; }
            if (run > maxRun) return true;
        }
        return false;
    }

    function evaluate(password, cfg) {
        const c = classesOf(password);
        const count = Object.values(c).filter(Boolean).length;
        const length = password.length;
        let targetLength;

        if (cfg.mode === 'bsi') {
            if (count >= 4) targetLength = 8;
            else if (cfg.hasMfa && count >= 3) targetLength = 8;
            else targetLength = 25;
        } else {
            targetLength = cfg.minLength;
        }

        const sequentialOk = cfg.mode === 'bsi' || !cfg.maxSequential
            ? true
            : !hasLongSequential(password, cfg.maxSequential);

        return { c, length, targetLength, lengthOk: length >= targetLength, sequentialOk };
    }

    function setRow(li, ok, label) {
        if (!li) return;
        const mark = li.querySelector('.pw-strength-mark');
        const text = li.querySelector('.pw-strength-text');
        if (mark) mark.textContent = ok ? '✓' : '✗';
        li.style.color = ok ? '#198754' : '#dc3545';
        if (text && label !== undefined) text.textContent = label;
    }

    function init(container) {
        const configEl = document.getElementById(container.dataset.config);
        const field = document.getElementById(container.dataset.target);
        if (!configEl || !field) return;

        let cfg;
        try {
            cfg = JSON.parse(configEl.textContent || '{}');
        } catch (e) {
            return;
        }

        const rows = {
            length: container.querySelector('[data-check="length"]'),
            upper: container.querySelector('[data-check="upper"]'),
            lower: container.querySelector('[data-check="lower"]'),
            digit: container.querySelector('[data-check="digit"]'),
            special: container.querySelector('[data-check="special"]'),
            sequential: container.querySelector('[data-check="sequential"]'),
        };

        function update() {
            const r = evaluate(field.value, cfg);
            setRow(rows.length, r.lengthOk, 'Mindestens ' + r.targetLength + ' Zeichen');
            setRow(rows.upper, r.c.upper, 'Großbuchstaben');
            setRow(rows.lower, r.c.lower, 'Kleinbuchstaben');
            setRow(rows.digit, r.c.digit, 'Ziffern');
            setRow(rows.special, r.c.special, 'Sonderzeichen');
            setRow(rows.sequential, r.sequentialOk, 'Keine ' + (cfg.maxSequential + 1) + '+ aufeinanderfolgenden Zeichen');
        }

        field.addEventListener('input', update);
        update();
    }

    document.querySelectorAll('.pw-strength').forEach(init);
})();
