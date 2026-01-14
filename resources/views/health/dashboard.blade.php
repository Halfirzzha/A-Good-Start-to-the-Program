@php
    $projectName = $projectName ?? config('app.name');
    $appName = $projectName;
    $baseUrl = url('/');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="{{ __('health.meta_description') }}">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $appName }} · {{ __('health.title_suffix') }}</title>
        <link rel="canonical" href="{{ route('health.dashboard') }}">
        <link rel="preload" href="{{ asset('assets/health/health.js') }}" as="script">
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap');

            :root {
                --bg-1: #0b0f1b;
                --bg-2: #0f1626;
                --bg-3: #101a2f;
                --card: rgba(15, 21, 38, 0.92);
                --card-strong: rgba(18, 26, 48, 0.98);
                --ink-1: #f3f6ff;
                --ink-2: #c7d2fe;
                --ink-3: #94a3b8;
                --accent: #3fb9ff;
                --accent-warm: #f59e0b;
                --accent-soft: rgba(63, 185, 255, 0.18);
                --border: rgba(148, 163, 184, 0.18);
                --shadow: 0 40px 90px rgba(2, 6, 23, 0.8);
                font-family: 'Space Grotesk', 'Manrope', system-ui, sans-serif;
                color: var(--ink-1);
                background: var(--bg-1);
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                background: var(--bg-3);
            }

            .page {
                min-height: 100vh;
                padding: clamp(1.5rem, 2.5vw, 3rem);
                background:
                    radial-gradient(1200px 800px at 10% -20%, rgba(63, 185, 255, 0.25), transparent 60%),
                    radial-gradient(900px 700px at 95% 5%, rgba(245, 158, 11, 0.16), transparent 55%),
                    radial-gradient(800px 600px at 40% 120%, rgba(78, 107, 255, 0.18), transparent 60%),
                    linear-gradient(165deg, var(--bg-1), var(--bg-2) 45%, var(--bg-3));
                position: relative;
                overflow-x: hidden;
            }

            .health-backdrop {
                position: absolute;
                inset: 0;
                pointer-events: none;
                opacity: 0.6;
                background:
                    radial-gradient(circle at 12% 18%, rgba(63, 185, 255, 0.22), transparent 40%),
                    radial-gradient(circle at 85% 12%, rgba(245, 158, 11, 0.14), transparent 42%),
                    repeating-linear-gradient(135deg, rgba(148, 163, 184, 0.08), rgba(148, 163, 184, 0.08) 1px, transparent 1px, transparent 72px);
            }

            .health-backdrop::before,
            .health-backdrop::after {
                content: '';
                position: absolute;
                inset: -20%;
                background:
                    radial-gradient(circle at 20% 70%, rgba(99, 102, 241, 0.18), transparent 45%),
                    radial-gradient(circle at 70% 30%, rgba(14, 116, 144, 0.2), transparent 40%);
                filter: blur(12px);
                opacity: 0.7;
                mix-blend-mode: screen;
            }

            .dashboard-shell {
                position: relative;
                z-index: 1;
                border-radius: 28px;
                padding: clamp(1.5rem, 3vw, 2.75rem);
                background: linear-gradient(160deg, rgba(20, 28, 50, 0.95), rgba(14, 19, 34, 0.92));
                border: 1px solid rgba(148, 163, 184, 0.2);
                box-shadow: var(--shadow);
                backdrop-filter: blur(6px);
            }

            .hero {
                display: flex;
                flex-wrap: wrap;
                align-items: flex-start;
                justify-content: space-between;
                gap: 1.5rem;
                padding-bottom: 1.5rem;
                border-bottom: 1px solid rgba(148, 163, 184, 0.12);
            }

            .eyebrow {
                text-transform: uppercase;
                letter-spacing: 0.3em;
                font-size: 0.7rem;
                color: var(--ink-3);
                margin: 0 0 0.4rem;
            }

            .hero h1 {
                margin: 0;
                font-size: clamp(2rem, 3.8vw, 2.8rem);
            }

            .hero-subtitle {
                margin: 0.6rem 0 0;
                color: var(--ink-2);
                max-width: 60ch;
                line-height: 1.6;
            }

            .hero-meta {
                display: grid;
                gap: 0.6rem;
                min-width: 240px;
            }

            .meta {
                color: var(--ink-3);
                font-size: 0.85rem;
            }

            .badge {
                display: inline-flex;
                align-items: center;
                gap: 0.35rem;
                padding: 0.4rem 0.9rem;
                border-radius: 999px;
                font-size: 0.85rem;
                font-weight: 600;
                letter-spacing: 0.03em;
                background: var(--accent-soft);
                color: var(--accent);
            }

            .badge.small {
                padding: 0.25rem 0.6rem;
                font-size: 0.7rem;
                letter-spacing: 0.08em;
            }

            .badge.ok {
                background: rgba(16, 185, 129, 0.2);
                color: #34d399;
            }

            .badge.degraded {
                background: rgba(248, 113, 113, 0.2);
                color: #fb7185;
            }

            .badge.scheduled {
                background: rgba(245, 158, 11, 0.2);
                color: #fbbf24;
            }

            .badge.warn {
                background: rgba(245, 158, 11, 0.18);
                color: #fbbf24;
            }

            .badge.neutral {
                background: rgba(148, 163, 184, 0.2);
                color: #e2e8f0;
            }

            .overview-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                gap: 1.25rem;
                margin-top: 1.5rem;
            }

            .card {
                background: var(--card-strong);
                border-radius: 20px;
                padding: 1.25rem 1.35rem;
                border: 1px solid rgba(148, 163, 184, 0.14);
                display: grid;
                gap: 0.6rem;
                box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.02);
                position: relative;
                overflow: hidden;
            }

            .card::after {
                content: '';
                position: absolute;
                inset: 0;
                border-radius: inherit;
                pointer-events: none;
                background: radial-gradient(circle at 20% 0%, rgba(63, 185, 255, 0.12), transparent 55%);
                opacity: 0;
                transition: opacity 240ms ease;
            }

            .card:hover::after {
                opacity: 1;
            }

            .card h2 {
                margin: 0;
                font-size: 0.95rem;
                text-transform: uppercase;
                letter-spacing: 0.16em;
                color: var(--ink-3);
            }

            .metric {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.6rem;
                font-size: 0.95rem;
            }

            .metric strong {
                font-weight: 600;
                color: var(--ink-1);
            }

            .metric span {
                color: var(--ink-2);
            }

            .table-card {
                margin-top: 1.5rem;
                background: var(--card-strong);
                border-radius: 22px;
                border: 1px solid rgba(148, 163, 184, 0.12);
                padding: 1.25rem;
            }

            .alert-banner {
                display: none;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
                padding: 0.85rem 1.2rem;
                border-radius: 16px;
                background: rgba(248, 113, 113, 0.14);
                border: 1px solid rgba(248, 113, 113, 0.35);
                color: #fecaca;
                margin-bottom: 1.25rem;
            }

            .alert-banner.warn {
                background: rgba(245, 158, 11, 0.16);
                border-color: rgba(245, 158, 11, 0.35);
                color: #fde68a;
            }

            .alert-banner strong {
                font-weight: 600;
            }

            .sparkline {
                display: grid;
                gap: 0.4rem;
            }

            .sparkline canvas {
                width: 100%;
                height: 54px;
                border-radius: 12px;
                background: rgba(15, 23, 42, 0.6);
                border: 1px solid rgba(148, 163, 184, 0.12);
            }

            .sparkline-labels {
                display: flex;
                justify-content: space-between;
                font-size: 0.75rem;
                color: var(--ink-3);
            }

            .table-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 1rem;
                margin-bottom: 0.75rem;
            }

            .table-header h2 {
                margin: 0;
                font-size: 1rem;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 0.9rem;
            }

            th, td {
                padding: 0.75rem 0.5rem;
                text-align: left;
                border-bottom: 1px solid rgba(148, 163, 184, 0.15);
            }

            th {
                text-transform: uppercase;
                letter-spacing: 0.1em;
                font-size: 0.7rem;
                color: var(--ink-3);
            }

            .details-grid {
                display: grid;
                gap: 1.25rem;
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                margin-top: 1.5rem;
            }

            .info-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 0.5rem;
                font-size: 0.9rem;
            }

            .info-table td {
                padding: 0.5rem 0.6rem;
                border-bottom: 1px solid rgba(148, 163, 184, 0.15);
            }

            .info-table td:first-child {
                font-weight: 600;
                color: var(--ink-2);
                width: 40%;
            }

            .info-table tr:last-child td {
                border-bottom: none;
            }

            .link {
                color: var(--accent);
                text-decoration: none;
            }

            .mono {
                font-family: 'JetBrains Mono', ui-monospace, monospace;
                font-variant-numeric: tabular-nums;
            }

            .security-list {
                display: grid;
                gap: 0.6rem;
                margin-top: 0.6rem;
            }

            .security-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 0.6rem;
                font-size: 0.9rem;
            }

            @media (max-width: 720px) {
                .hero {
                    flex-direction: column;
                    align-items: flex-start;
                }

                th, td {
                    padding: 0.65rem 0.4rem;
                }
            }
        </style>
    </head>
    <body>
        <main class="page" role="main">
            <div class="health-backdrop" aria-hidden="true"></div>
            <div class="dashboard-shell">
                <div class="alert-banner" id="health-alert">
                    <div>
                        <strong id="health-alert-title">{{ __('health.alert.title') }}</strong>
                        <span id="health-alert-detail">—</span>
                    </div>
                    <span class="badge small neutral" id="health-alert-badge">—</span>
                </div>
                <header class="hero">
                    <div>
                        <p class="eyebrow">{{ __('health.hero.eyebrow') }}</p>
                        <h1>{{ $appName }}</h1>
                        <p class="hero-subtitle">{{ __('health.hero.subtitle') }}</p>
                    </div>
                    <div class="hero-meta">
                        <span id="overall-status" class="badge ok">{{ __('health.hero.loading') }}</span>
                        <span class="meta">{{ __('health.hero.last_update') }}: <span id="last-updated" class="mono"> — </span></span>
                        <span class="meta">{{ __('health.hero.server_time') }}: <span id="server-time" class="mono"> — </span></span>
                        <span class="meta">{{ __('health.hero.refresh_interval', ['seconds' => '5s']) }}</span>
                    </div>
                </header>

                <section class="overview-grid" aria-live="polite" aria-atomic="true">
                    <article class="card">
                        <h2>{{ __('health.cards.overall') }}</h2>
                        <div class="metric">
                            <strong>{{ __('health.labels.status') }}</strong>
                            <span id="overall-details">{{ __('health.table.waiting') }}</span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.checks_ok') }}</strong>
                            <span class="mono" id="checks-ok"> — </span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.checks_degraded') }}</strong>
                            <span class="mono" id="checks-degraded"> — </span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.total_latency') }}</strong>
                            <span class="mono" id="checks-duration"> — </span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.avg_latency') }}</strong>
                            <span class="mono" id="checks-avg"> — </span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.stability') }}</strong>
                            <span class="badge small neutral" id="sla-status"> — </span>
                        </div>
                        <div class="sparkline">
                            <canvas id="latency-sparkline" width="240" height="54"
                                aria-label="{{ __('health.aria.latency_trend') }}"></canvas>
                        </div>
                    </article>

                    <article class="card">
                        <h2>{{ __('health.cards.maintenance') }}</h2>
                        <div class="metric">
                            <strong>{{ __('health.labels.status') }}</strong>
                            <span id="maintenance-state"> — </span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.mode') }}</strong>
                            <span id="maintenance-mode"> — </span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.window') }}</strong>
                            <span id="maintenance-window"> — </span>
                        </div>
                    </article>

                    <article class="card">
                        <h2>{{ __('health.cards.system') }}</h2>
                        <div class="metric">
                            <strong>{{ __('health.labels.version') }}</strong>
                            <span class="mono" id="app-version"> — </span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.uptime') }}</strong>
                            <span class="mono" id="app-uptime"> — </span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.scheduler') }}</strong>
                            <span class="badge small neutral" id="scheduler-status"> — </span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.storage') }}</strong>
                            <span class="badge small neutral" id="storage-status"> — </span>
                        </div>
                    </article>

                    <article class="card">
                        <h2>{{ __('health.cards.checks_summary') }}</h2>
                        <div class="metric">
                            <strong>{{ __('health.labels.total_checks') }}</strong>
                            <span class="mono" id="checks-total"> — </span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.queue_depth') }}</strong>
                            <span class="mono" id="queue-depth"> — </span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.failed_jobs') }}</strong>
                            <span class="mono" id="failed-jobs"> — </span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.uptime_signal') }}</strong>
                            <span class="mono" id="uptime-signal"> — </span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.cache_driver') }}</strong>
                            <span class="mono" id="cache-driver"> — </span>
                        </div>
                    </article>

                    <article class="card">
                        <h2>{{ __('health.cards.resources') }}</h2>
                        <div class="metric">
                            <strong>{{ __('health.labels.cpu') }}</strong>
                            <span class="mono" id="resource-cpu"> — </span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.memory') }}</strong>
                            <span class="mono" id="resource-memory"> — </span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.disk') }}</strong>
                            <span class="mono" id="resource-disk"> — </span>
                        </div>
                        <div class="metric">
                            <strong>{{ __('health.labels.resource_status') }}</strong>
                            <span class="badge small neutral" id="resource-status"> — </span>
                        </div>
                        <div class="sparkline">
                            <div class="sparkline-labels">
                                <span>{{ __('health.labels.cpu') }}</span>
                                <span id="cpu-peak">{{ __('health.labels.peak', ['value' => '—']) }}</span>
                            </div>
                            <canvas id="cpu-sparkline" width="240" height="54"
                                aria-label="{{ __('health.aria.cpu_trend') }}"></canvas>
                            <div class="sparkline-labels">
                                <span>{{ __('health.labels.memory') }}</span>
                                <span id="memory-peak">{{ __('health.labels.peak', ['value' => '—']) }}</span>
                            </div>
                            <canvas id="memory-sparkline" width="240" height="54"
                                aria-label="{{ __('health.aria.memory_trend') }}"></canvas>
                            <div class="sparkline-labels">
                                <span>{{ __('health.labels.disk') }}</span>
                                <span id="disk-peak">{{ __('health.labels.peak', ['value' => '—']) }}</span>
                            </div>
                            <canvas id="disk-sparkline" width="240" height="54"
                                aria-label="{{ __('health.aria.disk_trend') }}"></canvas>
                        </div>
                    </article>
                </section>

                <section class="table-card">
                    <div class="table-header">
                        <h2>{{ __('health.cards.component_checks') }}</h2>
                        <span class="meta">{{ __('health.table.summary') }}</span>
                    </div>
                    <div style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>{{ __('health.labels.component') }}</th>
                                    <th>{{ __('health.labels.status') }}</th>
                                    <th>{{ __('health.labels.latency') }}</th>
                                    <th>{{ __('health.labels.notes') }}</th>
                                </tr>
                            </thead>
                            <tbody id="checks-body">
                                <tr><td colspan="4" class="meta">{{ __('health.table.waiting') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="details-grid">
                    <article class="card">
                        <h2>{{ __('health.cards.maintenance_detail') }}</h2>
                        <table class="info-table" aria-live="polite" aria-atomic="true">
                            <tbody id="maintenance-table-body">
                                <tr><td colspan="2" class="meta">{{ __('health.table.waiting') }}</td></tr>
                            </tbody>
                        </table>
                    </article>
                    <article class="card">
                        <h2>{{ __('health.cards.security_baseline') }}</h2>
                        <div class="metric">
                            <strong>{{ __('health.labels.status') }}</strong>
                            <span class="badge small neutral" id="security-status"> — </span>
                        </div>
                        <div class="security-list">
                            <div class="security-item">
                                <span>{{ __('health.labels.email_verification') }}</span>
                                <span class="badge small neutral" id="security-email"> — </span>
                            </div>
                            <div class="security-item">
                                <span>{{ __('health.labels.session_stamp') }}</span>
                                <span class="badge small neutral" id="security-session"> — </span>
                            </div>
                            <div class="security-item">
                                <span>{{ __('health.labels.account_status') }}</span>
                                <span class="badge small neutral" id="security-account"> — </span>
                            </div>
                            <div class="security-item">
                                <span>{{ __('health.labels.threat_detection') }}</span>
                                <span class="badge small neutral" id="security-threat"> — </span>
                            </div>
                            <div class="security-item">
                                <span>{{ __('health.labels.developer_bypass') }}</span>
                                <span class="badge small neutral" id="security-bypass"> — </span>
                            </div>
                        </div>
                        <p class="meta" id="security-reason">—</p>
                    </article>
                    <article class="card">
                        <h2>{{ __('health.cards.runtime_snapshot') }}</h2>
                        <table class="info-table" aria-live="polite" aria-atomic="true">
                            <tbody id="runtime-table-body">
                                <tr><td colspan="2" class="meta">{{ __('health.table.waiting') }}</td></tr>
                            </tbody>
                        </table>
                    </article>
                    <article class="card">
                        <h2>{{ __('health.cards.ops_notes') }}</h2>
                        <table class="info-table">
                            <tbody>
                                <tr>
                                    <td>{{ __('health.labels.endpoint') }}</td>
                                    <td><a class="link mono" href="{{ url('/health/check') }}" target="_blank">/health/check</a></td>
                                </tr>
                                <tr>
                                    <td>{{ __('health.labels.dashboard') }}</td>
                                    <td class="mono">{{ route('health.dashboard') }}</td>
                                </tr>
                                <tr>
                                    <td>{{ __('health.labels.refresh') }}</td>
                                    <td class="mono">5s</td>
                                </tr>
                                <tr>
                                    <td>{{ __('health.labels.scope') }}</td>
                                    <td>{{ __('health.labels.scope_value') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </article>
                </section>
            </div>
        </main>
        <script>
            window.__healthConfig = {
                checkUrl: "{{ route('health.check') }}",
                dashboardUrl: "{{ route('health.dashboard') }}",
            };
        </script>
        <script src="{{ asset('assets/health/health.js') }}" defer></script>
    </body>
</html>
