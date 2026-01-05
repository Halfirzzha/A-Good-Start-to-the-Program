(() => {
    const adminStatus = document.querySelector(
        "[data-maintenance-admin-status]"
    );
    const adminWindow = document.querySelector(
        "[data-maintenance-admin-window]"
    );
    const adminNext = document.querySelector("[data-maintenance-admin-next]");
    const adminRetry = document.querySelector("[data-maintenance-admin-retry]");

    if (!adminStatus && !adminWindow && !adminNext) {
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
            return date.toISOString();
        }
    };

    const formatReadableDuration = (totalSeconds) => {
        if (!Number.isFinite(totalSeconds) || totalSeconds < 0) {
            return null;
        }
        const seconds = Math.floor(totalSeconds);
        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const parts = [];
        if (days > 0) {
            parts.push(`${days} hari`);
        }
        if (hours > 0 || days > 0) {
            parts.push(`${hours} jam`);
        }
        parts.push(`${minutes} menit`);
        return parts.join(" ");
    };

    const formatRelative = (target, now, prefixFuture, prefixPast) => {
        if (!target || !now) {
            return null;
        }
        const diffSeconds = Math.round(
            (target.getTime() - now.getTime()) / 1000
        );
        const readable = formatReadableDuration(Math.abs(diffSeconds));
        if (!readable) {
            return null;
        }
        return diffSeconds >= 0
            ? `${prefixFuture} ${readable}`
            : `${prefixPast} ${readable}`;
    };

    const applyPayload = (payload) => {
        const now = parseDate(payload.server_now) || new Date();
        const startAt = parseDate(payload.start_at);
        const endAt = parseDate(payload.end_at);
        const timezone = payload.timezone || "UTC";

        if (adminStatus) {
            adminStatus.textContent = payload.status_label || "Unknown";
        }

        if (adminWindow) {
            const parts = [];
            if (startAt) {
                parts.push(`start: ${formatDateTime(startAt, timezone)}`);
            }
            if (endAt) {
                parts.push(`end: ${formatDateTime(endAt, timezone)}`);
            }
            adminWindow.textContent = parts.length
                ? parts.join(" · ")
                : "Belum dijadwalkan";
        }

        if (adminNext) {
            let text = "Tidak ada perubahan terjadwal.";
            if (payload.is_scheduled && startAt) {
                const rel = formatRelative(
                    startAt,
                    now,
                    "Mulai dalam",
                    "Dimulai"
                );
                if (rel) {
                    text = rel;
                }
            } else if (payload.is_active && endAt) {
                const rel = formatRelative(
                    endAt,
                    now,
                    "Selesai dalam",
                    "Selesai"
                );
                if (rel) {
                    text = rel;
                }
            }
            adminNext.textContent = text;
        }

        if (adminRetry) {
            const retrySeconds =
                typeof payload.retry_after === "number"
                    ? payload.retry_after
                    : null;
            const readable =
                retrySeconds !== null
                    ? formatReadableDuration(retrySeconds)
                    : null;
            adminRetry.textContent = readable
                ? `${readable} (${Math.round(retrySeconds)} detik)`
                : "—";
        }
    };

    let lastErrorAt = 0;
    let errorCount = 0;
    let pollingStopped = false;
    let pollIntervalId = null;
    const ERROR_THROTTLE_MS = 30000;
    const MAX_BACKOFF_MS = 60000;
    const MAX_CONSECUTIVE_ERRORS = 20;
    const POLL_NORMAL_MS = 10000;

    const getStatusEndpoint = () => {
        const base = window.location.origin || "";
        return `${base}/maintenance/status?_=${Date.now()}`;
    };

    const computePollDelay = () => {
        if (errorCount > 0) {
            return Math.min(
                POLL_NORMAL_MS * Math.pow(1.5, errorCount) + Math.random() * 1000,
                MAX_BACKOFF_MS
            );
        }
        return POLL_NORMAL_MS;
    };

    const refreshStatus = async () => {
        if (pollingStopped) return;

        const endpoint = getStatusEndpoint();

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
                errorCount++;
                const now = Date.now();
                if (now - lastErrorAt > ERROR_THROTTLE_MS) {
                    lastErrorAt = now;
                    console.warn(
                        `[Maintenance-RT] Status fetch failed: HTTP ${response.status}`,
                        `| Attempt: ${errorCount}/${MAX_CONSECUTIVE_ERRORS}`
                    );
                }
                scheduleNextPoll();
                return;
            }

            const contentType = response.headers.get("Content-Type") || "";
            if (!contentType.includes("application/json")) {
                errorCount++;
                scheduleNextPoll();
                return;
            }

            const data = await response.json();
            errorCount = 0;
            applyPayload(data);
        } catch (error) {
            errorCount++;
            const now = Date.now();
            if (now - lastErrorAt > ERROR_THROTTLE_MS) {
                lastErrorAt = now;
                console.warn(
                    `[Maintenance-RT] Fetch error: ${error.message || error}`,
                    `| Attempt: ${errorCount}/${MAX_CONSECUTIVE_ERRORS}`
                );
            }
        }

        if (errorCount >= MAX_CONSECUTIVE_ERRORS && !pollingStopped) {
            pollingStopped = true;
            if (pollIntervalId) {
                clearInterval(pollIntervalId);
                pollIntervalId = null;
            }
            console.warn("[Maintenance-RT] Polling stopped after max errors. Click to retry.");
            document.addEventListener("click", () => {
                if (pollingStopped) {
                    pollingStopped = false;
                    errorCount = 0;
                    startPolling();
                }
            }, { once: true });
        }
    };

    const scheduleNextPoll = () => {
        if (pollingStopped) return;
        const delay = computePollDelay();
        window.setTimeout(refreshStatus, delay);
    };

    const startPolling = () => {
        refreshStatus();
        pollIntervalId = window.setInterval(refreshStatus, POLL_NORMAL_MS);
    };

    let sseRetryCount = 0;
    const MAX_SSE_RETRIES = 3;

    const getStreamEndpoint = () => {
        const base = window.location.origin || "";
        return `${base}/maintenance/stream`;
    };

    const startStream = () => {
        if (typeof EventSource === "undefined") {
            return false;
        }

        const endpoint = getStreamEndpoint();
        let source;

        try {
            source = new EventSource(endpoint);
        } catch (error) {
            return false;
        }

        source.addEventListener("open", () => {
            sseRetryCount = 0;
            errorCount = 0;
        });

        source.addEventListener("status", (event) => {
            try {
                applyPayload(JSON.parse(event.data));
                errorCount = 0;
            } catch (error) {
                // Ignore parse errors.
            }
        });

        source.addEventListener("error", () => {
            source.close();
            sseRetryCount++;

            if (sseRetryCount <= MAX_SSE_RETRIES) {
                window.setTimeout(() => startStream(), 2000 * sseRetryCount);
            } else {
                errorCount = 0;
                startPolling();
            }
        });

        return true;
    };

    if (!startStream()) {
        startPolling();
    }

    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) {
            refreshStatus();
        }
    });
})();
