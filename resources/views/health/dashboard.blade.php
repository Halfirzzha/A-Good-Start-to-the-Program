@php
    $projectName = $projectName ?? config('app.name');
    $appName = $projectName;
    $baseUrl = url('/');
@endphp
<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Dashboard kesehatan sistem real-time: database, cache, queue, dan maintenance status.">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $appName }} · Health Dashboard</title>
        <link rel="canonical" href="{{ route('health.dashboard') }}">
        <link rel="preload" href="{{ asset('assets/health/health.js') }}" as="script">
        <style>
            :root {
                font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
                background: #0b1221;
                color: #e2e8f0;
            }
            body {
                margin: 0;
                background: #030712;
            }
            .page {
                min-height: 100vh;
                padding: 2rem;
                background: radial-gradient(circle at top, rgba(56, 189, 248, 0.25), transparent 40%), #030712;
            }
            .panel {
                background: rgba(15, 23, 42, 0.9);
                border-radius: 1rem;
                padding: 1.5rem;
                box-shadow: 0 30px 80px rgba(15,23,42,0.6);
            }
            .grid {
                display: grid;
                gap: 1.5rem;
            }
            @media (min-width: 768px) {
                .grid {
                    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                }
            }
            .dashboard-shell {
                position: relative;
                z-index: 2;
                padding: 2rem;
                border-radius: 1.5rem;
                background: rgba(9, 18, 40, 0.85);
                border: 1px solid rgba(148, 163, 184, 0.1);
                box-shadow: 0 40px 90px rgba(2, 6, 23, 0.8);
            }
            .health-backdrop {
                position: absolute;
                inset: 0;
                background: radial-gradient(circle at 20% 20%, rgba(56, 189, 248, 0.2), transparent 35%),
                    radial-gradient(circle at 80% 0%, rgba(249, 115, 22, 0.15), transparent 45%),
                    linear-gradient(135deg, rgba(14, 165, 233, 0.08), rgba(99, 102, 241, 0.15));
                overflow: hidden;
                pointer-events: none;
            }
            .health-backdrop::after,
            .health-backdrop::before {
                content: '';
                position: absolute;
                inset: 0;
                background: repeating-linear-gradient(
                    120deg,
                    rgba(15, 118, 255, 0.08),
                    rgba(15, 118, 255, 0.08) 1px,
                    transparent 1px,
                    transparent 60px
                );
                mix-blend-mode: screen;
                opacity: 0.4;
            }
            .health-backdrop::before {
                transform: translateZ(0);
                opacity: 0.2;
                background: radial-gradient(circle at 10% 20%, rgba(16, 185, 129, 0.35), transparent 35%);
            }
            .hero-header {
                margin-bottom: 1.5rem;
            }
            .hero-header h1 {
                margin: 0.25rem 0;
                font-size: clamp(2rem, 3vw, 2.5rem);
            }
            .hero-subtitle {
                margin: 0;
                color: #94a3b8;
                line-height: 1.4;
            }
            .hero-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
                gap: 1.5rem;
                margin-bottom: 1.5rem;
            }
            .info-card {
                background: rgba(12, 23, 40, 0.85);
                padding: 1.25rem;
                border-radius: 1rem;
                border: 1px solid rgba(148, 163, 184, 0.12);
                box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.02);
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
                position: relative;
            }
            .info-card::after {
                content: '';
                position: absolute;
                inset: 1px;
                border-radius: inherit;
                pointer-events: none;
                border: 1px solid rgba(59, 130, 246, 0.25);
                opacity: 0;
                transition: opacity 0.3s ease;
            }
            .info-card:hover::after {
                opacity: 1;
            }
            .info-card-header {
                display: flex;
                justify-content: space-between;
                align-items: baseline;
            }
            .info-card-header .meta {
                font-size: 0.8rem;
            }
            .info-card-body {
                display: flex;
                flex-direction: column;
                gap: 0.35rem;
            }
            .status-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .info-card--payload .raw {
                margin-top: 1rem;
            }
            .badge {
                display: inline-flex;
                align-items: center;
                gap: 0.35rem;
                padding: 0.35rem 0.75rem;
                border-radius: 999px;
                font-size: 0.9rem;
                font-weight: 600;
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
                background: rgba(251, 191, 36, 0.2);
                color: #fbbf24;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 0.9rem;
            }
            th, td {
                padding: 0.7rem 0.5rem;
                text-align: left;
                border-bottom: 1px solid rgba(148,163,184,0.2);
            }
            th {
                text-transform: uppercase;
                letter-spacing: 0.05em;
                font-size: 0.75rem;
                color: #94a3b8;
            }
            .meta {
                color: #94a3b8;
                font-size: 0.85rem;
            }
            .raw {
                margin-top: 1rem;
                padding: 1rem;
                border-radius: 0.75rem;
                background: rgba(15, 23, 42, 0.8);
                font-size: 0.8rem;
                line-height: 1.5;
                overflow-x: auto;
            }
            .link {
                color: #38bdf8;
                text-decoration: none;
            }
            .status-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 0.5rem;
            }
            .info-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 0.5rem;
                font-size: 0.9rem;
            }
            .info-table th,
            .info-table td {
                padding: 0.45rem 0.5rem;
                border-bottom: 1px solid rgba(148, 163, 184, 0.2);
            }
            .info-table td:first-child {
                font-weight: 600;
                width: 40%;
            }
            .info-table tr:last-child td {
                border-bottom: none;
            }
            .panel-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 1.25rem;
                margin-top: 1.25rem;
            }
            .panel-grid h2 {
                margin: 0;
                font-size: 1rem;
                color: #e2e8f0;
            }
            .table-wrapper {
                border-radius: 0.75rem;
                padding: 0.25rem;
                background: rgba(15, 23, 42, 0.6);
            }
        </style>
    </head>
    <body>
        <main class="page" role="main">
            <div class="health-backdrop" aria-hidden="true"></div>
            <div class="dashboard-shell panel">
                <header class="hero-header">
                    <p class="meta">Warex Management System · Real-time observability</p>
                    <h1>{{ $appName }} · System Health</h1>
                    <p class="hero-subtitle">Monitoring instan untuk database, cache, queue, dan maintenance indicator — hadir dengan UI yang disusun secara profesional dan mudah dibaca.</p>
                </header>
                <section class="hero-grid" aria-live="polite" aria-atomic="true">
                    <article class="info-card info-card--status" id="overall-panel">
                        <div class="info-card-header">
                            <span class="meta">Overall status</span>
                            <span class="meta">Realtime · Data terakhir di {{ now()->format('H:i:s') }}</span>
                        </div>
                        <div class="info-card-body">
                            <div class="status-row">
                                <span id="overall-status" class="badge ok">Loading…</span>
                                <span class="meta" id="last-updated">—</span>
                            </div>
                            <p id="overall-details" class="meta">Menunggu data...</p>
                        </div>
                    </article>
                    <article class="info-card info-card--maintenance">
                        <div class="info-card-header">
                            <span class="meta">Maintenance window</span>
                            <span class="meta">Refresh setiap 5 detik</span>
                        </div>
                        <div class="info-card-body">
                            <p id="maintenance-status" class="meta">Memuat informasi...</p>
                            <div class="status-row">
                                <span class="meta" id="maintenance-mode-note">Mode: —</span>
                                <span class="meta" id="maintenance-active-note">Aktif: —</span>
                            </div>
                        </div>
                    </article>
                </section>
                <section class="panel-grid">
                    <article class="info-card info-card--checks">
                        <header>
                            <h2>Checks</h2>
                        </header>
                        <div style="overflow-x:auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Komponen</th>
                                        <th>Status</th>
                                        <th>Durasi</th>
                                        <th>Detail</th>
                                    </tr>
                                </thead>
                                <tbody id="checks-body">
                                    <tr><td colspan="4" class="meta">Menunggu data...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </article>
                    <article class="info-card info-card--maintenance-detail">
                        <header>
                            <h2>Maintenance detail</h2>
                        </header>
                        <div class="table-wrapper" style="overflow-x:auto;">
                            <table class="info-table" aria-live="polite" aria-atomic="true">
                                <tbody id="maintenance-table-body">
                                    <tr><td colspan="2" class="meta">Menunggu data...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </article>
                    <article class="info-card info-card--payload">
                        <header>
                            <h2>Payload terakhir</h2>
                        </header>
                        <div class="table-wrapper" style="overflow-x:auto;">
                            <table class="info-table" aria-live="polite" aria-atomic="true">
                                <tbody id="payload-table-body">
                                    <tr><td colspan="2" class="meta">Menunggu data...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="raw" id="raw-payload">Loading…</div>
                    </article>
                </section>
                <section style="margin-top:1.5rem;">
                    <p class="meta">Route health: <a class="link" href="{{ url('/health/check') }}" target="_blank">/health/check</a>.</p>
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
