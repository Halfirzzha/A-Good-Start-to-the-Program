(() => {
    const formatDuration = (ms) => {
        if (ms === null || ms === undefined) {
            return '—';
        }

        const seconds = Math.round(ms / 1000);
        if (seconds < 1) {
            return `${ms.toFixed(0)} ms`;
        }

        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        const parts = [];
        if (mins > 0) {
            parts.push(`${mins}m`);
        }
        parts.push(`${secs}s`);

        return parts.join(' ');
    };

    const formatDateTime = (value) => {
        if (!value) {
            return null;
        }

        try {
            return new Date(value).toLocaleString('id-ID');
        } catch (error) {
            console.warn('Unable to parse date', error);
        }

        return null;
    };

    const populateDetailTable = (tbody, rows) => {
        tbody.innerHTML = '';

        rows = rows.filter((row) => row && Object.prototype.hasOwnProperty.call(row, 'label'));
        if (!rows.length) {
            const empty = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 2;
            cell.className = 'meta';
            cell.textContent = 'Data belum tersedia.';
            empty.appendChild(cell);
            tbody.appendChild(empty);
            return;
        }

        rows.forEach(({ label, value }) => {
            const row = document.createElement('tr');
            const labelCell = document.createElement('td');
            labelCell.textContent = label;
            const valueCell = document.createElement('td');
            valueCell.textContent = value ?? '—';
            valueCell.setAttribute('data-label', label);
            row.append(labelCell, valueCell);
            tbody.appendChild(row);
        });
    };

    const healthConfig = window.__healthConfig ?? {};
    const HEALTH_CHECK_ENDPOINT = typeof healthConfig.checkUrl === 'string' && healthConfig.checkUrl !== ''
        ? healthConfig.checkUrl
        : '/health/check';
    const REFRESH_INTERVAL = 5000;

    const statusEl = document.getElementById('overall-status');
    const detailsEl = document.getElementById('overall-details');
    const updatedEl = document.getElementById('last-updated');
    const checksBody = document.getElementById('checks-body');
    const payloadEl = document.getElementById('raw-payload');
    const maintenanceEl = document.getElementById('maintenance-status');
    const maintenanceModeNote = document.getElementById('maintenance-mode-note');
    const maintenanceActiveNote = document.getElementById('maintenance-active-note');
    const maintenanceTable = document.getElementById('maintenance-table-body');
    const payloadTable = document.getElementById('payload-table-body');

    const setStatusBadge = (status) => {
        if (!statusEl) {
            return;
        }
        statusEl.classList.remove('ok', 'degraded', 'scheduled');
        if (status === 'ok') {
            statusEl.classList.add('ok');
            statusEl.textContent = 'OK';
        } else if (status === 'degraded') {
            statusEl.classList.add('degraded');
            statusEl.textContent = 'DEGRADED';
        } else {
            statusEl.classList.add('scheduled');
            statusEl.textContent = status.toUpperCase();
        }
    };

    const applyData = (data) => {
        const status = data.overall_status || 'degraded';
        setStatusBadge(status);

        if (detailsEl) {
            const summary = data.checks
                ? Object.values(data.checks).map((check) => `${check.name}: ${check.status}`).join(' • ')
                : 'Menunggu hasil check.';
            detailsEl.textContent = summary;
        }

        if (updatedEl && data.timestamp) {
            updatedEl.textContent = new Date(data.timestamp).toLocaleTimeString();
        }

        if (checksBody) {
            checksBody.innerHTML = '';
            const checks = data.checks || {};
            if (Object.keys(checks).length === 0) {
                const empty = document.createElement('tr');
                const cell = document.createElement('td');
                cell.colSpan = 4;
                cell.textContent = 'Menunggu hasil check...';
                cell.className = 'meta';
                empty.appendChild(cell);
                checksBody.appendChild(empty);
            } else {
                for (const check of Object.values(checks)) {
                    const row = document.createElement('tr');
                    const typeCell = document.createElement('td');
                    typeCell.textContent = check.name;
                    const statusCell = document.createElement('td');
                    const helper = document.createElement('span');
                    helper.classList.add('badge');
                    helper.classList.add(check.status === 'ok' ? 'ok' : 'degraded');
                    helper.textContent = check.status.toUpperCase();
                    statusCell.appendChild(helper);
                    const durationCell = document.createElement('td');
                    durationCell.textContent = formatDuration(check.duration_ms);
                    const detailsCell = document.createElement('td');
                    detailsCell.textContent = check.details || '';
                    if (check.meta && Object.keys(check.meta).length > 0) {
                        const metaText = Object.entries(check.meta)
                            .map(([key, value]) => `${key}: ${JSON.stringify(value)}`)
                            .join(', ');
                        detailsCell.textContent += ` (${metaText})`;
                    }
                    row.append(typeCell, statusCell, durationCell, detailsCell);
                    checksBody.appendChild(row);
                }
            }
        }

        if (payloadEl) {
            payloadEl.textContent = JSON.stringify(data, null, 2);
        }

        if (maintenanceEl && data.maintenance) {
            const parts = [
                `Mode: ${data.maintenance.mode}`,
                `Aktif: ${data.maintenance.enabled ? 'Ya' : 'Tidak'}`,
            ];
            if (data.maintenance.start_at) {
                parts.push(`Mulai: ${new Date(data.maintenance.start_at).toLocaleString('id-ID')}`);
            }
            if (data.maintenance.end_at) {
                parts.push(`Selesai: ${new Date(data.maintenance.end_at).toLocaleString('id-ID')}`);
            }
            maintenanceEl.textContent = parts.join(' · ');
        }

        if (maintenanceModeNote && data.maintenance) {
            maintenanceModeNote.textContent = `Mode: ${data.maintenance.mode ?? '—'}`;
        }

        if (maintenanceActiveNote && data.maintenance) {
            maintenanceActiveNote.textContent = `Aktif: ${data.maintenance.enabled ? 'Ya' : 'Tidak'}`;
        }

        if (maintenanceTable) {
            populateDetailTable(maintenanceTable, [
                { label: 'Mode', value: data.maintenance?.mode ?? '—' },
                { label: 'Aktif', value: data.maintenance?.enabled ? 'Ya' : 'Tidak' },
                { label: 'Judul', value: data.maintenance?.title ?? '—' },
                { label: 'Ringkasan', value: data.maintenance?.summary ?? '—' },
                { label: 'Mulai', value: formatDateTime(data.maintenance?.start_at) ?? '—' },
                { label: 'Selesai', value: formatDateTime(data.maintenance?.end_at) ?? '—' },
            ]);
        }

        if (payloadTable) {
            populateDetailTable(payloadTable, [
                { label: 'Status keseluruhan', value: status.toUpperCase() },
                { label: 'Durasi pemeriksaan', value: formatDuration(data.duration_ms) },
                { label: 'Waktu terupdate', value: formatDateTime(data.timestamp) ?? '—' },
                { label: 'Jumlah pemeriksaan', value: Object.keys(data.checks || {}).length.toString() },
                { label: 'Maintenance mode', value: data.maintenance?.mode ?? '—' },
            ]);
        }
    };

    const fetchHealth = async () => {
        try {
            const endpoint = new URL(HEALTH_CHECK_ENDPOINT, window.location.origin);
            endpoint.searchParams.set('ts', Date.now().toString());

            const response = await fetch(endpoint.href, {
                headers: {
                    'Cache-Control': 'no-store',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!response.ok) {
                throw new Error('Failed to fetch health data');
            }

            const data = await response.json();
            applyData(data);
        } catch (error) {
            detailsEl.textContent = 'Unable to refresh health data.';
            console.error(error);
        } finally {
            window.setTimeout(fetchHealth, REFRESH_INTERVAL);
        }
    };

    fetchHealth();
})();
