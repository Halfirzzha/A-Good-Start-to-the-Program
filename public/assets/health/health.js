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
            return new Date(value).toLocaleString('id-ID', {
                hour12: false,
            });
        } catch (error) {
            console.warn('Unable to parse date', error);
        }

        return null;
    };

    const formatTime = (value) => {
        if (!value) {
            return null;
        }
        try {
            return new Date(value).toLocaleTimeString('id-ID', { hour12: false });
        } catch (error) {
            return null;
        }
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
    const serverTimeEl = document.getElementById('server-time');
    const checksTotalEl = document.getElementById('checks-total');
    const checksOkEl = document.getElementById('checks-ok');
    const checksDegradedEl = document.getElementById('checks-degraded');
    const checksDurationEl = document.getElementById('checks-duration');
    const checksAvgEl = document.getElementById('checks-avg');
    const maintenanceStateEl = document.getElementById('maintenance-state');
    const maintenanceModeEl = document.getElementById('maintenance-mode');
    const maintenanceWindowEl = document.getElementById('maintenance-window');
    const queueDepthEl = document.getElementById('queue-depth');
    const uptimeSignalEl = document.getElementById('uptime-signal');
    const appVersionEl = document.getElementById('app-version');
    const appUptimeEl = document.getElementById('app-uptime');
    const schedulerStatusEl = document.getElementById('scheduler-status');
    const storageStatusEl = document.getElementById('storage-status');
    const resourceCpuEl = document.getElementById('resource-cpu');
    const resourceMemoryEl = document.getElementById('resource-memory');
    const resourceDiskEl = document.getElementById('resource-disk');
    const resourceStatusEl = document.getElementById('resource-status');
    const maintenanceTable = document.getElementById('maintenance-table-body');

    const latencyHistory = [];

    const formatSize = (mb, unit = 'MB') => {
        if (!Number.isFinite(mb)) {
            return '—';
        }
        if (unit === 'GB') {
            return `${mb.toFixed(2)} GB`;
        }
        if (mb >= 1024) {
            return `${(mb / 1024).toFixed(1)} GB`;
        }
        return `${Math.round(mb)} MB`;
    };

    const setBadge = (el, status, labels = {}) => {
        if (!el) {
            return;
        }
        el.classList.remove('ok', 'degraded', 'scheduled', 'warn', 'neutral');
        const normalized = status || 'neutral';
        const label = labels[normalized] || normalized.toUpperCase();

        if (normalized === 'ok') {
            el.classList.add('ok');
        } else if (normalized === 'degraded') {
            el.classList.add('degraded');
        } else if (normalized === 'restricted') {
            el.classList.add('warn');
        } else {
            el.classList.add('neutral');
        }

        el.textContent = label;
    };

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

        const checks = data.checks || {};
        const checkValues = Object.values(checks);
        const okCount = checkValues.filter((check) => check.status === 'ok').length;
        const degradedCount = checkValues.filter((check) => check.status !== 'ok').length;
        const totalDuration = checkValues.reduce((total, check) => total + (check.duration_ms ?? 0), 0);

        if (detailsEl) {
            const summary = checkValues.length
                ? `${okCount}/${checkValues.length} checks OK`
                : 'Menunggu hasil check.';
            detailsEl.textContent = summary;
        }

        if (updatedEl && data.timestamp) {
            updatedEl.textContent = formatTime(data.timestamp) ?? '—';
        }

        if (checksBody) {
            checksBody.innerHTML = '';
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
                    detailsCell.textContent = check.details || '—';
                    if (check.meta && typeof check.meta.pending_jobs === 'number') {
                        detailsCell.textContent += ` · Queue depth: ${check.meta.pending_jobs}`;
                    }
                    row.append(typeCell, statusCell, durationCell, detailsCell);
                    checksBody.appendChild(row);
                }
            }
        }

        if (serverTimeEl && data.timestamp) {
            serverTimeEl.textContent = formatTime(data.timestamp) ?? '—';
        }

        if (checksTotalEl) {
            checksTotalEl.textContent = checkValues.length.toString();
        }

        if (checksOkEl) {
            checksOkEl.textContent = okCount.toString();
        }

        if (checksDegradedEl) {
            checksDegradedEl.textContent = degradedCount.toString();
        }

        if (checksDurationEl) {
            checksDurationEl.textContent = formatDuration(totalDuration);
        }

        if (checksAvgEl) {
            if (Number.isFinite(data.duration_ms)) {
                latencyHistory.push(data.duration_ms);
            }
            if (latencyHistory.length > 5) {
                latencyHistory.shift();
            }
            const avg = latencyHistory.length
                ? latencyHistory.reduce((sum, value) => sum + value, 0) / latencyHistory.length
                : null;
            checksAvgEl.textContent = avg === null ? '—' : formatDuration(avg);
        }

        if (queueDepthEl) {
            const queueMeta = checks.queue?.meta;
            const depth = typeof queueMeta?.pending_jobs === 'number' ? queueMeta.pending_jobs : null;
            queueDepthEl.textContent = depth !== null ? depth.toString() : '—';
        }

        if (uptimeSignalEl) {
            uptimeSignalEl.textContent = status === 'ok' ? 'Stable' : 'Investigate';
        }

        if (appVersionEl) {
            appVersionEl.textContent = data.app?.version ?? 'unknown';
        }

        if (appUptimeEl) {
            const uptimeSeconds = data.app?.uptime_seconds;
            if (typeof uptimeSeconds === 'number') {
                const days = Math.floor(uptimeSeconds / 86400);
                const hours = Math.floor((uptimeSeconds % 86400) / 3600);
                const minutes = Math.floor((uptimeSeconds % 3600) / 60);
                const parts = [];
                if (days > 0) {
                    parts.push(`${days}d`);
                }
                if (hours > 0 || days > 0) {
                    parts.push(`${hours}h`);
                }
                parts.push(`${minutes}m`);
                appUptimeEl.textContent = parts.join(' ');
            } else {
                appUptimeEl.textContent = '—';
            }
        }

        if (schedulerStatusEl) {
            const scheduler = checks.scheduler;
            if (scheduler?.status) {
                const lastRun = scheduler.meta?.last_run ? formatDateTime(scheduler.meta.last_run) : null;
                setBadge(schedulerStatusEl, scheduler.status, {
                    ok: 'OK',
                    degraded: 'DEGRADED',
                    restricted: 'RESTRICTED',
                });
                if (lastRun && schedulerStatusEl.classList.contains('ok')) {
                    schedulerStatusEl.title = `Last run: ${lastRun}`;
                }
            }
        }

        if (storageStatusEl) {
            const storage = checks.storage;
            if (storage?.status) {
                const freeMb = storage.meta?.free_mb;
                setBadge(storageStatusEl, storage.status, {
                    ok: 'OK',
                    degraded: 'DEGRADED',
                    restricted: 'RESTRICTED',
                });
                if (Number.isFinite(freeMb)) {
                    storageStatusEl.title = `${freeMb} MB free`;
                }
            }
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

        if (maintenanceStateEl) {
            let maintenanceState = 'Off';
            const startAt = data.maintenance?.start_at ? new Date(data.maintenance.start_at) : null;
            const endAt = data.maintenance?.end_at ? new Date(data.maintenance.end_at) : null;
            const now = data.timestamp ? new Date(data.timestamp) : new Date();

            if (data.maintenance?.enabled) {
                maintenanceState = 'Active';
            } else if (startAt && now < startAt) {
                maintenanceState = 'Scheduled';
            } else if (endAt && now > endAt) {
                maintenanceState = 'Ended';
            }

            maintenanceStateEl.textContent = maintenanceState;
        }

        if (maintenanceModeEl) {
            maintenanceModeEl.textContent = data.maintenance?.mode ?? '—';
        }

        if (maintenanceWindowEl) {
            const start = formatDateTime(data.maintenance?.start_at);
            const end = formatDateTime(data.maintenance?.end_at);
            maintenanceWindowEl.textContent = start || end ? `${start ?? '—'} → ${end ?? '—'}` : '—';
        }

        if (resourceStatusEl) {
            const systemCheck = checks.system;
            if (systemCheck?.status) {
                setBadge(resourceStatusEl, systemCheck.status, {
                    ok: 'OK',
                    restricted: 'RESTRICTED',
                    degraded: 'DEGRADED',
                });
            }
        }

        if (resourceCpuEl) {
            const system = checks.system;
            if (system?.status === 'restricted') {
                resourceCpuEl.textContent = 'Privasi Provider - data sensitif';
            } else if (system?.meta?.cpu_usage_pct !== null && system?.meta?.cpu_usage_pct !== undefined) {
                const usage = system.meta.cpu_usage_pct;
                const cores = system.meta.cpu_cores ?? '—';
                resourceCpuEl.textContent = `${usage.toFixed(1)}% · ${cores} cores`;
            } else {
                resourceCpuEl.textContent = '—';
            }
        }

        if (resourceMemoryEl) {
            const system = checks.system;
            if (system?.status === 'restricted') {
                resourceMemoryEl.textContent = 'Privasi Provider - data sensitif';
            } else if (system?.meta?.memory_total_mb !== null && system?.meta?.memory_used_mb !== undefined) {
                const used = system.meta.memory_used_mb;
                const total = system.meta.memory_total_mb;
                resourceMemoryEl.textContent = `${formatSize(used)} / ${formatSize(total)}`;
            } else {
                resourceMemoryEl.textContent = '—';
            }
        }

        if (resourceDiskEl) {
            const system = checks.system;
            if (system?.status === 'restricted') {
                resourceDiskEl.textContent = 'Privasi Provider - data sensitif';
            } else if (system?.meta?.disk_total_gb !== null && system?.meta?.disk_used_gb !== undefined) {
                const used = system.meta.disk_used_gb;
                const total = system.meta.disk_total_gb;
                resourceDiskEl.textContent = `${formatSize(used, 'GB')} / ${formatSize(total, 'GB')}`;
            } else {
                resourceDiskEl.textContent = '—';
            }
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
