(() => {
    const root = document.body;
    if (!root) {
        return;
    }

    const themeToggle = document.querySelector('[data-theme-toggle]');

    const setTheme = (theme, persist = true) => {
        root.dataset.theme = theme;
        if (persist) {
            try {
                localStorage.setItem('maintenanceTheme', theme);
            } catch (error) {
                // Ignore storage errors.
            }
        }

        if (themeToggle) {
            const isDark = theme === 'dark';
            themeToggle.textContent = isDark ? 'Light mode' : 'Dark mode';
            themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        }
    };

    try {
        const stored = localStorage.getItem('maintenanceTheme');
        if (stored === 'light' || stored === 'dark') {
            setTheme(stored, false);
        } else {
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            setTheme(prefersDark ? 'dark' : 'light', false);
        }
    } catch (error) {
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        setTheme(prefersDark ? 'dark' : 'light', false);
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const nextTheme = root.dataset.theme === 'dark' ? 'light' : 'dark';
            setTheme(nextTheme);
        });
    }

    const container = document.querySelector('[data-maintenance]');
    if (!container) {
        return;
    }

    const parseDate = (value) => {
        if (!value) {
            return null;
        }
        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    };

    const formatDateTime = (date, timezone) => {
        const pad = (value) => String(value).padStart(2, '0');
        const year = date.getFullYear();
        const month = pad(date.getMonth() + 1);
        const day = pad(date.getDate());
        const hours = pad(date.getHours());
        const minutes = pad(date.getMinutes());
        const seconds = pad(date.getSeconds());
        return `${year}-${month}-${day} ${hours}:${minutes}:${seconds} ${timezone}`;
    };

    const formatDuration = (ms) => {
        const clamped = Math.max(0, ms);
        const totalSeconds = Math.floor(clamped / 1000);
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        const milli = Math.floor(clamped % 1000);
        const pad = (value, size) => String(value).padStart(size, '0');
        return `${pad(hours, 2)}:${pad(minutes, 2)}:${pad(seconds, 2)}.${pad(milli, 3)}`;
    };

    let timezone = container.dataset.timezone || 'UTC';
    let serverNow = parseDate(container.dataset.serverNow);
    let startAt = parseDate(container.dataset.maintenanceStart);
    let endAt = parseDate(container.dataset.maintenanceEnd);

    const nowEl = container.querySelector('[data-time="now"]');
    const startEl = container.querySelector('[data-time="start"]');
    const endEl = container.querySelector('[data-time="end"]');
    const elapsedEl = container.querySelector('[data-elapsed]');
    const remainingEl = container.querySelector('[data-remaining]');
    const statusIndicator = container.querySelector('[data-status]');
    const statusMessage = container.querySelector('[data-status-message]');
    const retryRow = container.querySelector('[data-time="retry"]');
    const retryValue = retryRow?.querySelector('[data-time-value]');
    const heroTitle = container.querySelector('.hero-content h1');
    const heroSummary = container.querySelector('.hero-content .lead');
    const noteField = container.querySelector('[data-maintenance-note-field]');
    const tokenInput = container.querySelector('#maintenance-token');
    const tokenSubmit = container.querySelector('[data-maintenance-submit]');
    const tokenFeedback = container.querySelector('[data-token-feedback]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    const initialRetryRaw = container.dataset.maintenanceRetry;

    const ensureNote = () => {
        if (!noteField) {
            return;
        }
        const note = container.dataset.maintenanceNote;
        if (note) {
            noteField.textContent = note;
        }
    };

    const setTokenFeedback = (message, tone = 'info') => {
        if (!tokenFeedback) {
            return;
        }
        tokenFeedback.textContent = message;
        tokenFeedback.dataset.tone = tone;
    };

    if (tokenInput && tokenSubmit) {
        const defaultLabel = tokenSubmit.textContent;
        const submitToken = async () => {
            const token = tokenInput.value.trim();
            if (!token) {
                setTokenFeedback('Token akses wajib diisi.', 'error');
                tokenInput.focus();
                return;
            }

            tokenSubmit.disabled = true;
            tokenSubmit.textContent = 'Memverifikasi...';
            setTokenFeedback('Mengirim token ke server...', 'info');

            try {
                const response = await fetch('/maintenance/bypass', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                    },
                    body: JSON.stringify({ token }),
                });

                if (response.ok) {
                    setTokenFeedback('Token diterima. Mengalihkan...', 'success');
                    window.location.reload();
                    return;
                }

                const payload = await response.json().catch(() => ({}));
                setTokenFeedback(payload.message || 'Token tidak valid atau sudah kedaluwarsa.', 'error');
            } catch (error) {
                setTokenFeedback('Gagal menghubungi server. Coba lagi.', 'error');
            } finally {
                tokenSubmit.disabled = false;
                tokenSubmit.textContent = defaultLabel;
            }
        };

        tokenSubmit.addEventListener('click', () => {
            submitToken();
        });

        tokenInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                submitToken();
            }
        });
    }

    const updateTimers = () => {
        if (!serverNow) {
            serverNow = new Date();
        }
        const now = serverNow;

        if (nowEl) {
            nowEl.textContent = formatDateTime(now, timezone);
        }

        if (startEl) {
            startEl.textContent = startAt ? formatDateTime(startAt, timezone) : 'Belum ditentukan';
        }

        if (endEl) {
            endEl.textContent = endAt ? formatDateTime(endAt, timezone) : 'Belum ditentukan';
        }

        if (elapsedEl) {
            elapsedEl.textContent = startAt ? formatDuration(now.getTime() - startAt.getTime()) : '00:00:00.000';
        }

        if (remainingEl) {
            remainingEl.textContent = endAt ? formatDuration(endAt.getTime() - now.getTime()) : '00:00:00.000';
        }
    };

    const setStatus = (payload) => {
        const modeSuffix = payload.mode && payload.mode !== 'global' ? ` (${payload.mode})` : '';
        const label = payload.status_label ? `${payload.status_label}${modeSuffix}` : 'Maintenance mode aktif';

        if (statusIndicator) {
            statusIndicator.textContent = label;
        }

        if (statusMessage) {
            const detail = payload.is_active
                ? 'Akses terbatas sampai maintenance selesai.'
                : payload.status_label === 'Scheduled'
                    ? 'Maintenance dijadwalkan, silakan kembali nanti.'
                    : 'Maintenance tidak aktif saat ini.';
            statusMessage.textContent = `${label} · ${detail}`;
        }
    };

    const updateRetryRow = (seconds) => {
        if (!retryRow) {
            return;
        }

        if (typeof seconds === 'number' && seconds >= 0) {
            retryRow.style.display = '';
            if (retryValue) {
                retryValue.textContent = seconds.toString();
            }
        } else {
            retryRow.style.display = 'none';
            if (retryValue) {
                retryValue.textContent = '--';
            }
        }
    };

    const parseRetryValue = (value) => {
        if (value === undefined || value === null || value === '') {
            return null;
        }

        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : null;
    };

    const applyPayload = (payload) => {
        const parsedServer = parseDate(payload.server_now);
        if (parsedServer) {
            serverNow = parsedServer;
        }

        startAt = parseDate(payload.start_at) ?? startAt;
        endAt = parseDate(payload.end_at) ?? endAt;

        if (payload.timezone) {
            timezone = payload.timezone;
        }

        if (heroTitle && payload.title) {
            heroTitle.textContent = payload.title;
        }

        if (heroSummary && payload.summary) {
            heroSummary.textContent = payload.summary;
        }

        if (noteField) {
            noteField.textContent = payload.note ?? noteField.textContent;
        }

        if (payload.status_label || payload.mode) {
            setStatus(payload);
        }

        updateRetryRow(payload.retry_after);
        updateTimers();
    };

    const refreshStatus = async () => {
        try {
            const response = await fetch(`/maintenance/status?ts=${Date.now()}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Cache-Control': 'no-store',
                },
            });

            if (!response.ok) {
                throw new Error('Unable to fetch maintenance status');
            }

            const data = await response.json();
            applyPayload(data);
        } catch (error) {
            console.warn('Unable to refresh maintenance status', error);
        } finally {
            statusPollTimeout = window.setTimeout(refreshStatus, 10000);
        }
    };

    let statusPollTimeout;

    const tickClock = () => {
        if (serverNow) {
            serverNow = new Date(serverNow.getTime() + 1000);
        } else {
            serverNow = new Date();
        }
        updateTimers();
    };

    ensureNote();
    updateTimers();
    updateRetryRow(parseRetryValue(initialRetryRaw));
    refreshStatus();
    window.setInterval(tickClock, 1000);
})();
