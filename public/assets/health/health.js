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
    const slaStatusEl = document.getElementById('sla-status');
    const maintenanceStateEl = document.getElementById('maintenance-state');
    const maintenanceModeEl = document.getElementById('maintenance-mode');
    const maintenanceWindowEl = document.getElementById('maintenance-window');
    const queueDepthEl = document.getElementById('queue-depth');
    const failedJobsEl = document.getElementById('failed-jobs');
    const uptimeSignalEl = document.getElementById('uptime-signal');
    const cacheDriverEl = document.getElementById('cache-driver');
    const appVersionEl = document.getElementById('app-version');
    const appUptimeEl = document.getElementById('app-uptime');
    const schedulerStatusEl = document.getElementById('scheduler-status');
    const storageStatusEl = document.getElementById('storage-status');
    const resourceCpuEl = document.getElementById('resource-cpu');
    const resourceMemoryEl = document.getElementById('resource-memory');
    const resourceDiskEl = document.getElementById('resource-disk');
    const resourceStatusEl = document.getElementById('resource-status');
    const securityStatusEl = document.getElementById('security-status');
    const securityEmailEl = document.getElementById('security-email');
    const securitySessionEl = document.getElementById('security-session');
    const securityAccountEl = document.getElementById('security-account');
    const securityThreatEl = document.getElementById('security-threat');
    const securityBypassEl = document.getElementById('security-bypass');
    const securityReasonEl = document.getElementById('security-reason');
    const runtimeTable = document.getElementById('runtime-table-body');
    const maintenanceTable = document.getElementById('maintenance-table-body');
    const alertBanner = document.getElementById('health-alert');
    const alertTitle = document.getElementById('health-alert-title');
    const alertDetail = document.getElementById('health-alert-detail');
    const alertBadge = document.getElementById('health-alert-badge');
    const latencyCanvas = document.getElementById('latency-sparkline');
    const cpuCanvas = document.getElementById('cpu-sparkline');
    const memoryCanvas = document.getElementById('memory-sparkline');
    const diskCanvas = document.getElementById('disk-sparkline');
    const cpuPeakEl = document.getElementById('cpu-peak');
    const memoryPeakEl = document.getElementById('memory-peak');
    const diskPeakEl = document.getElementById('disk-peak');

    const latencyHistory = [];
    const cpuHistory = [];
    const memoryHistory = [];
    const diskHistory = [];
    const statusHistory = [];

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

    const drawSparkline = (canvas, data, stroke = 'rgba(63, 185, 255, 0.8)') => {
        if (!canvas || !canvas.getContext) {
            return;
        }
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return;
        }
        const width = canvas.width;
        const height = canvas.height;
        ctx.clearRect(0, 0, width, height);

        if (!Array.isArray(data) || data.length < 2) {
            ctx.fillStyle = 'rgba(148, 163, 184, 0.3)';
            ctx.fillRect(0, height - 2, width, 2);
            return;
        }

        const min = Math.min(...data);
        const max = Math.max(...data);
        const span = max - min || 1;

        ctx.strokeStyle = stroke;
        ctx.lineWidth = 2;
        ctx.beginPath();

        data.forEach((value, index) => {
            const x = (index / (data.length - 1)) * (width - 8) + 4;
            const y = height - 6 - ((value - min) / span) * (height - 12);
            if (index === 0) {
                ctx.moveTo(x, y);
            } else {
                ctx.lineTo(x, y);
            }
        });

        ctx.stroke();
    };

    const pushHistory = (list, value) => {
        if (!Number.isFinite(value)) {
            return;
        }
        list.push(value);
        if (list.length > 10) {
            list.shift();
        }
    };

    const peakValue = (list) => {
        if (!Array.isArray(list) || !list.length) {
            return null;
        }
        return Math.max(...list);
    };

    const setStatusBadge = (status) => {
        if (!statusEl) {
            return;
        }
        statusEl.classList.remove('ok', 'degraded', 'scheduled', 'warn');
        if (status === 'ok') {
            statusEl.classList.add('ok');
            statusEl.textContent = 'OK';
        } else if (status === 'warn') {
            statusEl.classList.add('warn');
            statusEl.textContent = 'WARN';
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
                    if (check.status === 'ok') {
                        helper.classList.add('ok');
                    } else if (check.status === 'warn') {
                        helper.classList.add('warn');
                    } else if (check.status === 'restricted') {
                        helper.classList.add('neutral');
                    } else {
                        helper.classList.add('degraded');
                    }
                    helper.textContent = check.status.toUpperCase();
                    statusCell.appendChild(helper);
                    const durationCell = document.createElement('td');
                    durationCell.textContent = formatDuration(check.duration_ms);
                    const detailsCell = document.createElement('td');
                    detailsCell.textContent = check.details || '—';
                    if (check.meta && typeof check.meta.pending_jobs === 'number') {
                        detailsCell.textContent += ` · Queue depth: ${check.meta.pending_jobs}`;
                    }
                    if (check.meta && typeof check.meta.failed_jobs === 'number') {
                        detailsCell.textContent += ` · Failed: ${check.meta.failed_jobs}`;
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

        if (slaStatusEl) {
            statusHistory.push(status);
            if (statusHistory.length > 10) {
                statusHistory.shift();
            }
            const okCountHist = statusHistory.filter((item) => item === 'ok').length;
            const okPct = statusHistory.length ? Math.round((okCountHist / statusHistory.length) * 100) : 0;
            const badgeStatus = status === 'degraded' ? 'degraded' : status === 'warn' ? 'warn' : 'ok';
            setBadge(slaStatusEl, badgeStatus, {
                ok: `STABLE ${okPct}%`,
                warn: `WARN ${okPct}%`,
                degraded: `DEGRADED ${okPct}%`,
            });
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

        if (latencyCanvas) {
            drawSparkline(latencyCanvas, latencyHistory);
        }

        if (queueDepthEl) {
            const queueMeta = checks.queue?.meta;
            const depth = typeof queueMeta?.pending_jobs === 'number' ? queueMeta.pending_jobs : null;
            queueDepthEl.textContent = depth !== null ? depth.toString() : '—';
        }

        if (failedJobsEl) {
            const queueMeta = checks.queue?.meta;
            const failed = queueMeta?.failed_jobs;
            if (typeof failed === 'number') {
                failedJobsEl.textContent = failed.toString();
            } else if (queueMeta?.failed_jobs_note) {
                failedJobsEl.textContent = 'Privasi Provider - data sensitif';
            } else {
                failedJobsEl.textContent = '—';
            }
        }

        if (uptimeSignalEl) {
            uptimeSignalEl.textContent = status === 'ok' ? 'Stable' : 'Investigate';
        }

        if (cacheDriverEl) {
            const cacheMeta = checks.cache?.meta;
            cacheDriverEl.textContent = cacheMeta?.driver ?? '—';
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

        if (alertBanner && alertTitle && alertDetail && alertBadge) {
            const overall = data.overall_status || 'ok';
            if (overall === 'ok') {
                alertBanner.style.display = 'none';
            } else {
                alertBanner.style.display = 'flex';
                alertBanner.classList.toggle('warn', overall === 'warn');
                alertTitle.textContent = overall === 'degraded' ? 'Degraded status detected' : 'Warning status detected';
                alertDetail.textContent = detailsEl?.textContent || 'Periksa status komponen.';
                setBadge(alertBadge, overall, { ok: 'OK', warn: 'WARN', degraded: 'DEGRADED' });
            }
        }

        if (securityStatusEl) {
            const security = checks.security;
            if (security?.status) {
                setBadge(securityStatusEl, security.status, {
                    ok: 'OK',
                    warn: 'NEEDS REVIEW',
                    degraded: 'DEGRADED',
                });
            }
        }

        if (securityEmailEl) {
            const enabled = checks.security?.meta?.enforce_email_verification;
            setBadge(securityEmailEl, enabled ? 'ok' : 'warn', { ok: 'ON', warn: 'OFF' });
        }

        if (securitySessionEl) {
            const enabled = checks.security?.meta?.enforce_session_stamp;
            setBadge(securitySessionEl, enabled ? 'ok' : 'warn', { ok: 'ON', warn: 'OFF' });
        }

        if (securityAccountEl) {
            const enabled = checks.security?.meta?.enforce_account_status;
            setBadge(securityAccountEl, enabled ? 'ok' : 'warn', { ok: 'ON', warn: 'OFF' });
        }

        if (securityThreatEl) {
            const enabled = checks.security?.meta?.threat_detection;
            setBadge(securityThreatEl, enabled ? 'ok' : 'warn', { ok: 'ON', warn: 'OFF' });
        }

        if (securityBypassEl) {
            const enabled = checks.security?.meta?.developer_bypass;
            setBadge(securityBypassEl, enabled ? 'warn' : 'ok', { ok: 'OFF', warn: 'ON' });
        }

        if (securityReasonEl) {
            const reasons = checks.security?.meta?.reasons;
            securityReasonEl.textContent = Array.isArray(reasons) && reasons.length
                ? reasons.join(' · ')
                : 'Baseline aman dan sesuai kebijakan.';
        }

        if (runtimeTable) {
            populateDetailTable(runtimeTable, [
                { label: 'App version', value: data.app?.version ?? '—' },
                { label: 'Laravel', value: data.app?.laravel_version ?? '—' },
                { label: 'PHP', value: data.app?.php_version ?? '—' },
                { label: 'Cache driver', value: data.app?.cache_driver ?? '—' },
                { label: 'Queue driver', value: data.app?.queue_driver ?? '—' },
                { label: 'Mail driver', value: data.app?.mail_driver ?? '—' },
                { label: 'Deployment', value: data.app?.deployment ?? '—' },
                { label: 'Timezone', value: data.app?.timezone ?? '—' },
            ]);
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
                pushHistory(cpuHistory, usage);
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
                if (Number.isFinite(used) && Number.isFinite(total) && total > 0) {
                    pushHistory(memoryHistory, (used / total) * 100);
                }
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
                if (Number.isFinite(used) && Number.isFinite(total) && total > 0) {
                    pushHistory(diskHistory, (used / total) * 100);
                }
            } else {
                resourceDiskEl.textContent = '—';
            }
        }

        if (cpuCanvas) {
            drawSparkline(cpuCanvas, cpuHistory, 'rgba(63, 185, 255, 0.85)');
        }

        if (memoryCanvas) {
            drawSparkline(memoryCanvas, memoryHistory, 'rgba(129, 140, 248, 0.85)');
        }

        if (diskCanvas) {
            drawSparkline(diskCanvas, diskHistory, 'rgba(56, 189, 248, 0.75)');
        }

        if (cpuPeakEl) {
            const peak = peakValue(cpuHistory);
            cpuPeakEl.textContent = peak === null ? 'Peak: —' : `Peak: ${peak.toFixed(1)}%`;
        }

        if (memoryPeakEl) {
            const peak = peakValue(memoryHistory);
            memoryPeakEl.textContent = peak === null ? 'Peak: —' : `Peak: ${peak.toFixed(1)}%`;
        }

        if (diskPeakEl) {
            const peak = peakValue(diskHistory);
            diskPeakEl.textContent = peak === null ? 'Peak: —' : `Peak: ${peak.toFixed(1)}%`;
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
