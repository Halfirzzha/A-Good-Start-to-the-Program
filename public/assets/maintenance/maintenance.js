(() => {
    const themeRoot = document.documentElement;
    const themeBody = document.body;
    if (!themeRoot || !themeBody) {
        return;
    }

    const themeToggle = document.querySelector("[data-theme-toggle]");

    const setTheme = (theme, persist = true) => {
        themeRoot.dataset.theme = theme;
        themeBody.dataset.theme = theme;
        if (persist) {
            try {
                localStorage.setItem("maintenanceTheme", theme);
            } catch (error) {
                // Ignore storage errors.
            }
        }

        if (themeToggle) {
            const isDark = theme === "dark";
            themeToggle.textContent = isDark ? "Light mode" : "Dark mode";
            themeToggle.setAttribute("aria-pressed", isDark ? "true" : "false");
        }
    };

    try {
        const stored = localStorage.getItem("maintenanceTheme");
        if (stored === "light" || stored === "dark") {
            setTheme(stored, false);
        } else {
            const prefersDark = window.matchMedia(
                "(prefers-color-scheme: dark)"
            ).matches;
            setTheme(prefersDark ? "dark" : "light", false);
        }
    } catch (error) {
        const prefersDark = window.matchMedia(
            "(prefers-color-scheme: dark)"
        ).matches;
        setTheme(prefersDark ? "dark" : "light", false);
    }

    if (themeToggle) {
        themeToggle.addEventListener("click", () => {
            const current = themeRoot.dataset.theme || themeBody.dataset.theme;
            const nextTheme = current === "dark" ? "light" : "dark";
            setTheme(nextTheme);
        });
    }

    const container = document.querySelector("[data-maintenance]");
    if (!container) {
        return;
    }

    const parseDate = (value) => {
        if (value === null || value === undefined || value === "") {
            return null;
        }
        if (value instanceof Date) {
            return Number.isNaN(value.getTime()) ? null : value;
        }
        if (typeof value === "number") {
            const parsed = new Date(value);
            return Number.isNaN(parsed.getTime()) ? null : parsed;
        }
        if (typeof value === "string") {
            const trimmed = value.trim();
            if (!trimmed) {
                return null;
            }
            if (/^\d+$/.test(trimmed)) {
                const parsed = new Date(Number(trimmed));
                return Number.isNaN(parsed.getTime()) ? null : parsed;
            }
            const parsed = new Date(trimmed);
            return Number.isNaN(parsed.getTime()) ? null : parsed;
        }
        return null;
    };

    const formatDateTime = (date, timezone) => {
        const pad = (value) => String(value).padStart(2, "0");
        try {
            const formatter = new Intl.DateTimeFormat("en-CA", {
                timeZone: timezone,
                year: "numeric",
                month: "2-digit",
                day: "2-digit",
                hour: "2-digit",
                minute: "2-digit",
                second: "2-digit",
                hour12: false,
            });
            const parts = formatter.formatToParts(date).reduce((acc, part) => {
                acc[part.type] = part.value;
                return acc;
            }, {});
            return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}:${parts.second} ${timezone}`;
        } catch (error) {
            const year = date.getFullYear();
            const month = pad(date.getMonth() + 1);
            const day = pad(date.getDate());
            const hours = pad(date.getHours());
            const minutes = pad(date.getMinutes());
            const seconds = pad(date.getSeconds());
            return `${year}-${month}-${day} ${hours}:${minutes}:${seconds} ${timezone}`;
        }
    };

    const formatDuration = (ms) => {
        const clamped = Math.max(0, ms);
        const totalSeconds = Math.floor(clamped / 1000);
        const days = Math.floor(totalSeconds / 86400);
        const hours = Math.floor((totalSeconds % 86400) / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        const pad = (value) => String(value).padStart(2, "0");
        const dayPrefix = days > 0 ? `${days}d ` : "";
        return `${dayPrefix}${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
    };

    const formatReadableDuration = (totalSeconds, options = {}) => {
        if (!Number.isFinite(totalSeconds) || totalSeconds < 0) {
            return null;
        }

        const maxUnits = Number.isFinite(options.maxUnits)
            ? options.maxUnits
            : 2;
        const capDays = Number.isFinite(options.capDays) ? options.capDays : 30;

        const seconds = Math.floor(totalSeconds);
        const days = Math.floor(seconds / 86400);

        if (days >= capDays) {
            return `>=${capDays} hari`;
        }

        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const parts = [];

        if (days > 0) {
            parts.push(`${days} hari`);
        }
        if (hours > 0) {
            parts.push(`${hours} jam`);
        }
        if (minutes > 0 || parts.length === 0) {
            parts.push(`${minutes} menit`);
        }

        return parts.slice(0, maxUnits).join(" ");
    };

    const formatRelative = (target, now, prefixFuture, prefixPast) => {
        if (!target || !now) {
            return null;
        }
        const diffSeconds = Math.round(
            (target.getTime() - now.getTime()) / 1000
        );
        const readable = formatReadableDuration(Math.abs(diffSeconds), {
            maxUnits: 2,
            capDays: 30,
        });
        if (!readable) {
            return null;
        }
        return diffSeconds >= 0
            ? `${prefixFuture} ${readable}`
            : `${prefixPast} ${readable}`;
    };

    let timezone = container.dataset.timezone || "UTC";
    let serverEpochMs = null;
    let clientEpochAtSync = null;
    let serverNow = parseDate(container.dataset.serverNow);
    let startAt = parseDate(container.dataset.maintenanceStart);
    let endAt = parseDate(container.dataset.maintenanceEnd);

    const nowEl = container.querySelector('[data-time="now"]');
    const startEl = container.querySelector('[data-time="start"]');
    const endEl = container.querySelector('[data-time="end"]');
    const elapsedEl = container.querySelector("[data-elapsed]");
    const remainingEl = container.querySelector("[data-remaining]");
    const statusIndicator = container.querySelector("[data-status]");
    const statusMessage = container.querySelector("[data-status-message]");
    const retryRow = container.querySelector('[data-time="retry"]');
    const retryValue = retryRow?.querySelector("[data-time-value]");
    const heroTitle = container.querySelector(".hero-content h1");
    const heroSummary = container.querySelector(".hero-content .lead");
    const noteField = container.querySelector("[data-maintenance-note-field]");
    const tokenInput = container.querySelector("#maintenance-token");
    const tokenSubmit = container.querySelector("[data-maintenance-submit]");
    const tokenFeedback = container.querySelector("[data-token-feedback]");
    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute("content");

    const initialRetryRaw = container.dataset.maintenanceRetry;

    const ensureNote = () => {
        if (!noteField) {
            return;
        }
        const note = container.dataset.maintenanceNote;
        if (note) {
            noteField.innerHTML = note;
        }
    };

    const setTokenFeedback = (message, tone = "info") => {
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
                setTokenFeedback("Token akses wajib diisi.", "error");
                tokenInput.focus();
                return;
            }

            tokenSubmit.disabled = true;
            tokenSubmit.textContent = "Memverifikasi...";
            setTokenFeedback("Mengirim token ke server...", "info");

            try {
                const response = await fetch("/maintenance/bypass", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                        ...(csrfToken ? { "X-CSRF-TOKEN": csrfToken } : {}),
                    },
                    body: JSON.stringify({ token }),
                });

                if (response.ok) {
                    setTokenFeedback(
                        "Token diterima. Mengalihkan...",
                        "success"
                    );
                    window.location.reload();
                    return;
                }

                const payload = await response.json().catch(() => ({}));
                setTokenFeedback(
                    payload.message ||
                        "Token tidak valid atau sudah kedaluwarsa.",
                    "error"
                );
            } catch (error) {
                setTokenFeedback(
                    "Gagal menghubungi server. Coba lagi.",
                    "error"
                );
            } finally {
                tokenSubmit.disabled = false;
                tokenSubmit.textContent = defaultLabel;
            }
        };

        tokenSubmit.addEventListener("click", () => {
            submitToken();
        });

        tokenInput.addEventListener("keydown", (event) => {
            if (event.key === "Enter") {
                event.preventDefault();
                submitToken();
            }
        });
    }

    const syncServerClock = (date) => {
        if (!date) {
            serverEpochMs = null;
            clientEpochAtSync = null;
            return;
        }
        serverEpochMs = date.getTime();
        clientEpochAtSync = Date.now();
    };

    const getServerNow = () => {
        if (serverEpochMs === null || clientEpochAtSync === null) {
            return new Date();
        }
        const delta = Date.now() - clientEpochAtSync;
        return new Date(serverEpochMs + delta);
    };

    const renderTimeValue = (el, mainText, metaText) => {
        if (!el) {
            return;
        }
        if (!metaText) {
            el.textContent = mainText;
            return;
        }
        el.innerHTML = `<span class="time-main">${mainText}</span><span class="time-meta">${metaText}</span>`;
    };

    const updateTimers = () => {
        const now = getServerNow();

        if (nowEl) {
            nowEl.textContent = formatDateTime(now, timezone);
        }

        if (startEl) {
            if (startAt) {
                const relative = formatRelative(
                    startAt,
                    now,
                    "Mulai dalam",
                    "Dimulai"
                );
                renderTimeValue(
                    startEl,
                    formatDateTime(startAt, timezone),
                    relative || null
                );
            } else {
                renderTimeValue(startEl, "Belum ditentukan");
            }
        }

        if (endEl) {
            if (endAt) {
                const relative = formatRelative(
                    endAt,
                    now,
                    "Selesai dalam",
                    "Selesai"
                );
                renderTimeValue(
                    endEl,
                    formatDateTime(endAt, timezone),
                    relative || null
                );
            } else {
                renderTimeValue(endEl, "Belum ditentukan");
            }
        }

        if (elapsedEl) {
            elapsedEl.textContent = startAt
                ? formatDuration(now.getTime() - startAt.getTime())
                : "--:--:--";
        }

        if (remainingEl) {
            remainingEl.textContent = endAt
                ? formatDuration(endAt.getTime() - now.getTime())
                : "--:--:--";
        }
    };

    const setStatus = (payload) => {
        const modeSuffix =
            payload.mode && payload.mode !== "global"
                ? ` (${payload.mode})`
                : "";
        const label = payload.status_label
            ? `${payload.status_label}${modeSuffix}`
            : "Maintenance mode aktif";

        if (statusIndicator) {
            statusIndicator.textContent = label;
        }

        if (statusMessage) {
            const detail = payload.is_active
                ? "Akses terbatas sampai maintenance selesai."
                : payload.status_label === "Scheduled"
                ? "Maintenance dijadwalkan, silakan kembali nanti."
                : "Maintenance tidak aktif saat ini.";
            statusMessage.textContent = `${label} · ${detail}`;
        }
    };

    const updateRetryRow = (seconds) => {
        if (!retryRow) {
            return;
        }

        if (endAt && serverNow) {
            const diffSeconds = Math.max(
                0,
                Math.round((endAt.getTime() - getServerNow().getTime()) / 1000)
            );
            const readable = formatReadableDuration(diffSeconds, {
                maxUnits: 2,
                capDays: 30,
            });
            if (retryValue) {
                if (diffSeconds >= 3600 && readable) {
                    renderTimeValue(retryValue, readable);
                } else if (readable) {
                    renderTimeValue(
                        retryValue,
                        readable,
                        `${diffSeconds} detik`
                    );
                } else {
                    retryValue.textContent = `${diffSeconds} detik`;
                }
            }
            retryRow.style.display = "";
            return;
        }

        if (typeof seconds === "number" && seconds >= 0) {
            retryRow.style.display = "";
            if (retryValue) {
                const readable = formatReadableDuration(seconds, {
                    maxUnits: 2,
                    capDays: 30,
                });
                const rounded = Math.round(seconds);
                if (rounded >= 3600 && readable) {
                    renderTimeValue(retryValue, readable);
                } else if (readable) {
                    renderTimeValue(retryValue, readable, `${rounded} detik`);
                } else {
                    retryValue.textContent = `${rounded} detik`;
                }
            }
        } else {
            retryRow.style.display = "none";
            if (retryValue) {
                retryValue.textContent = "--";
            }
        }
    };

    const parseRetryValue = (value) => {
        if (value === undefined || value === null || value === "") {
            return null;
        }

        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : null;
    };

    const deriveEndFromRetry = (retrySeconds) => {
        if (typeof retrySeconds !== "number" || retrySeconds < 0) {
            return null;
        }
        const base = serverNow ?? new Date();
        return new Date(base.getTime() + Math.floor(retrySeconds * 1000));
    };

    const applyPayload = (payload) => {
        const parsedServer = parseDate(payload.server_now);
        if (parsedServer) {
            serverNow = parsedServer;
            syncServerClock(parsedServer);
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
            const incoming = payload.note_html ?? payload.note;
            if (incoming) {
                noteField.innerHTML = incoming;
            }
        }

        if (payload.status_label || payload.mode) {
            setStatus(payload);
        }

        const retrySeconds =
            typeof payload.retry_after === "number"
                ? payload.retry_after
                : null;
        if (!endAt && retrySeconds !== null) {
            endAt = deriveEndFromRetry(retrySeconds) ?? endAt;
        }
        updateRetryRow(retrySeconds);
        updateTimers();
    };

    let lastStatusErrorAt = 0;
    let statusErrorCount = 0;
    let pollingStopped = false;
    const STATUS_ERROR_THROTTLE_MS = 30000;
    const MAX_BACKOFF_MS = 60000;
    const MAX_CONSECUTIVE_ERRORS = 20;
    const POLL_NORMAL_MS = 10000;
    const POLL_FAST_MS = 5000;
    const POLL_SLOW_MS = 30000;

    const connectionIndicator = document.querySelector("[data-status-message]");

    const setConnectionStatus = (status) => {
        if (!connectionIndicator) return;
        const baseText =
            connectionIndicator.dataset.originalText ||
            connectionIndicator.textContent;
        if (!connectionIndicator.dataset.originalText) {
            connectionIndicator.dataset.originalText = baseText;
        }
        if (status === "ok") {
            connectionIndicator.textContent = baseText;
            connectionIndicator.classList.remove(
                "connection-error",
                "connection-reconnecting"
            );
        } else if (status === "reconnecting") {
            connectionIndicator.textContent = baseText + " · Reconnecting...";
            connectionIndicator.classList.add("connection-reconnecting");
            connectionIndicator.classList.remove("connection-error");
        } else if (status === "error") {
            connectionIndicator.textContent = baseText + " · Offline";
            connectionIndicator.classList.add("connection-error");
            connectionIndicator.classList.remove("connection-reconnecting");
        }
    };

    const getStatusEndpoint = () => {
        const base = window.location.origin || "";
        return `${base}/maintenance/status?_=${Date.now()}`;
    };

    const computePollDelay = () => {
        if (statusErrorCount > 0) {
            const backoff = Math.min(
                POLL_NORMAL_MS * Math.pow(1.5, statusErrorCount) +
                    Math.random() * 1000,
                MAX_BACKOFF_MS
            );
            return Math.floor(backoff);
        }
        const now = new Date();
        if (startAt || endAt) {
            const soonThresholdMs = 60000;
            const startDelta = startAt
                ? Math.abs(startAt.getTime() - now.getTime())
                : Infinity;
            const endDelta = endAt
                ? Math.abs(endAt.getTime() - now.getTime())
                : Infinity;
            if (startDelta < soonThresholdMs || endDelta < soonThresholdMs) {
                return POLL_FAST_MS;
            }
        }
        return POLL_NORMAL_MS;
    };

    const refreshStatus = async () => {
        if (pollingStopped) return;

        const endpoint = getStatusEndpoint();
        let nextPollDelay = computePollDelay();

        try {
            const response = await fetch(endpoint, {
                method: "GET",
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    Accept: "application/json",
                    "Cache-Control": "no-store",
                },
                cache: "no-store",
                credentials: "same-origin",
            });

            if (!response.ok) {
                statusErrorCount++;
                setConnectionStatus(
                    statusErrorCount >= MAX_CONSECUTIVE_ERRORS
                        ? "error"
                        : "reconnecting"
                );
                const now = Date.now();
                if (now - lastStatusErrorAt > STATUS_ERROR_THROTTLE_MS) {
                    lastStatusErrorAt = now;
                    const contentType =
                        response.headers.get("Content-Type") || "unknown";
                    console.warn(
                        `[Maintenance] Status refresh failed: HTTP ${response.status} (${response.statusText})`,
                        `| Content-Type: ${contentType}`,
                        `| URL: ${endpoint}`,
                        `| Attempt: ${statusErrorCount}/${MAX_CONSECUTIVE_ERRORS}`
                    );
                }
                nextPollDelay = computePollDelay();
                return;
            }

            const contentType = response.headers.get("Content-Type") || "";
            if (!contentType.includes("application/json")) {
                statusErrorCount++;
                setConnectionStatus(
                    statusErrorCount >= MAX_CONSECUTIVE_ERRORS
                        ? "error"
                        : "reconnecting"
                );
                const now = Date.now();
                if (now - lastStatusErrorAt > STATUS_ERROR_THROTTLE_MS) {
                    lastStatusErrorAt = now;
                    const text = await response
                        .text()
                        .catch(() => "(unable to read body)");
                    console.warn(
                        `[Maintenance] Unexpected response type: ${contentType}`,
                        `| Body preview: ${text.substring(0, 200)}`,
                        `| URL: ${endpoint}`,
                        `| Hint: Check if login redirect or error page is returned`
                    );
                }
                nextPollDelay = computePollDelay();
                return;
            }

            let data;
            try {
                data = await response.json();
            } catch (parseError) {
                statusErrorCount++;
                setConnectionStatus("reconnecting");
                const now = Date.now();
                if (now - lastStatusErrorAt > STATUS_ERROR_THROTTLE_MS) {
                    lastStatusErrorAt = now;
                    console.warn(
                        "[Maintenance] JSON parse error:",
                        parseError.message,
                        `| URL: ${endpoint}`
                    );
                }
                nextPollDelay = computePollDelay();
                return;
            }

            statusErrorCount = 0;
            setConnectionStatus("ok");
            applyPayload(data);
        } catch (error) {
            statusErrorCount++;
            setConnectionStatus(
                statusErrorCount >= MAX_CONSECUTIVE_ERRORS
                    ? "error"
                    : "reconnecting"
            );
            const now = Date.now();
            if (now - lastStatusErrorAt > STATUS_ERROR_THROTTLE_MS) {
                lastStatusErrorAt = now;
                const errorType = error.name || "Error";
                const errorMsg = error.message || String(error);
                console.warn(
                    `[Maintenance] Fetch error: ${errorType}: ${errorMsg}`,
                    `| URL: ${endpoint}`,
                    `| Attempt: ${statusErrorCount}/${MAX_CONSECUTIVE_ERRORS}`,
                    `| Possible causes: CORS, network, mixed-content, or server down`
                );
            }
            nextPollDelay = computePollDelay();
        } finally {
            if (statusErrorCount >= MAX_CONSECUTIVE_ERRORS && !pollingStopped) {
                pollingStopped = true;
                console.warn(
                    `[Maintenance] Polling stopped after ${MAX_CONSECUTIVE_ERRORS} consecutive errors.`,
                    "| Click anywhere to retry, or refresh the page."
                );
                document.addEventListener(
                    "click",
                    () => {
                        if (pollingStopped) {
                            pollingStopped = false;
                            statusErrorCount = 0;
                            setConnectionStatus("reconnecting");
                            refreshStatus();
                        }
                    },
                    { once: true }
                );
                return;
            }
            statusPollTimeout = window.setTimeout(refreshStatus, nextPollDelay);
        }
    };

    let statusPollTimeout;
    let sseSource;
    let sseRetryCount = 0;
    const MAX_SSE_RETRIES = 3;

    const getStreamEndpoint = () => {
        const base = window.location.origin || "";
        return `${base}/maintenance/stream`;
    };

    const startStream = () => {
        if (typeof EventSource === "undefined") {
            console.info(
                "[Maintenance] EventSource not supported, falling back to polling"
            );
            return false;
        }

        const endpoint = getStreamEndpoint();

        try {
            sseSource = new EventSource(endpoint);
        } catch (error) {
            console.info(
                "[Maintenance] EventSource failed to initialize, using polling fallback"
            );
            return false;
        }

        sseSource.addEventListener("open", () => {
            sseRetryCount = 0;
        });

        sseSource.addEventListener("status", (event) => {
            try {
                const payload = JSON.parse(event.data);
                applyPayload(payload);
            } catch (error) {
                // Ignore parse errors for individual events.
            }
        });

        sseSource.addEventListener("error", () => {
            sseSource?.close();
            sseSource = null;
            sseRetryCount++;

            if (sseRetryCount <= MAX_SSE_RETRIES) {
                console.info(
                    `[Maintenance] SSE disconnected, retrying (${sseRetryCount}/${MAX_SSE_RETRIES})...`
                );
                window.setTimeout(() => {
                    if (!sseSource) {
                        startStream();
                    }
                }, 2000 * sseRetryCount);
            } else {
                console.info(
                    "[Maintenance] SSE failed after max retries, switching to polling"
                );
                refreshStatus();
            }
        });

        return true;
    };

    const tickClock = () => {
        updateTimers();
    };

    if (serverNow) {
        syncServerClock(serverNow);
    }
    ensureNote();
    updateTimers();
    const initialRetrySeconds = parseRetryValue(initialRetryRaw);
    if (!endAt && initialRetrySeconds !== null) {
        endAt = deriveEndFromRetry(initialRetrySeconds) ?? endAt;
    }
    updateRetryRow(initialRetrySeconds);
    if (!startStream()) {
        refreshStatus();
    }
    window.setInterval(tickClock, 1000);

    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) {
            refreshStatus();
            updateTimers();
        }
    });
})();
