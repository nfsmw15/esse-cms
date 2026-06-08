(function () {
    'use strict';

    const configEl = document.getElementById('admin-updater-config');
    if (!configEl) return;

    let config = {};
    try {
        config = JSON.parse(configEl.textContent || '{}');
    } catch (e) {
        return;
    }

    const btn = document.getElementById('btn-update');
    if (btn) {
        btn.addEventListener('click', function () {
            startUpdate(btn.dataset.version || config.version || '');
        });
    }

    function startUpdate(version) {
        if (!window.confirm('Update auf v' + version + ' wirklich starten? Ein Backup wird automatisch erstellt.')) return;

        const updateBtn = document.getElementById('btn-update');
        const terminalWrap = document.getElementById('terminal-wrap');
        const terminal = document.getElementById('terminal');
        const status = document.getElementById('terminal-status');
        if (!updateBtn || !terminalWrap || !terminal || !status) return;

        updateBtn.disabled = true;
        terminalWrap.classList.remove('admin-hidden');

        const fd = new FormData();
        fd.append('_csrf', config.csrf || '');
        fd.append('_action', 'prepare_run');

        fetch('/admin/update', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (!d.token) {
                    status.textContent = 'Token-Fehler';
                    return;
                }
                listenSSE(new EventSource('/admin/update/run?run_token=' + encodeURIComponent(d.token)), terminal, status, updateBtn);
            });
    }

    function listenSSE(es, terminal, status, updateBtn) {
        es.onmessage = (e) => {
            const data = JSON.parse(e.data);
            const line = document.createElement('div');

            if (data.type === 'success') {
                line.className = 'admin-log-success';
                line.textContent = '✓ ' + data.message;
            } else if (data.type === 'error') {
                line.className = 'admin-log-error';
                line.textContent = '✗ ' + data.message;
            } else {
                line.className = 'admin-log-info';
                line.textContent = '› ' + data.message;
            }

            terminal.appendChild(line);
            terminal.scrollTop = terminal.scrollHeight;

            if (data.type === 'done') {
                es.close();
                status.textContent = 'Abgeschlossen';
                status.className = 'badge bg-success';

                const reload = document.createElement('a');
                reload.href = '/admin/update';
                reload.className = 'btn btn-success btn-sm mt-3 d-block';
                reload.textContent = 'Seite neu laden →';
                terminal.after(reload);
            }

            if (data.type === 'error') {
                es.close();
                status.textContent = 'Fehler';
                status.className = 'badge bg-danger';
                updateBtn.disabled = false;
            }
        };

        es.onerror = () => {
            es.close();
            status.textContent = 'Verbindung unterbrochen';
            status.className = 'badge bg-danger';
        };
    }
})();
