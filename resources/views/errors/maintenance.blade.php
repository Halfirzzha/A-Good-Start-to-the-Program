@php
    $maintenanceData = $maintenanceData ?? [];
    $request = request();
    $statusCode = $statusCode ?? 503;
    $requestId = $requestId ?? $request->headers->get('X-Request-Id');
    $requestId = is_string($requestId) && $requestId !== '' ? $requestId : 'n/a';

    $normalizeDate = static function ($value) {
        if ($value instanceof \DateTimeInterface) {
            return $value->toIso8601String();
        }

        if (is_string($value) && $value !== '') {
            try {
                return \Illuminate\Support\Carbon::parse($value)->toIso8601String();
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    };

    $serverNow = $normalizeDate($serverNow ?? now()) ?? now()->toIso8601String();
    $timezone = config('app.timezone') ?: 'UTC';

    $startAt = $normalizeDate($maintenanceData['start_at'] ?? null);
    $endAt = $normalizeDate($maintenanceData['end_at'] ?? null);
    $noteHtml = $maintenanceNote ?? ($maintenanceData['note_html'] ?? ($maintenanceData['note'] ?? null));
    $noteText = is_string($noteHtml) ? trim(strip_tags($noteHtml)) : null;
    $retryAfter = $retryAfter ?? ($maintenanceData['retry'] ?? null);

    $appName = \App\Support\SystemSettings::getValue('project.name', config('app.name', 'System'));
    $title = $maintenanceData['title'] ?? ($title ?? __('maintenance.title_default'));
    $summary =
        $maintenanceData['summary'] ??
        ($summary ?? __('maintenance.summary_default'));

    $heroImageBase = 'assets/maintenance/maintenance-illustration';
    $heroImageOriginal = $heroImageBase . '.png';
    $heroImagePath = public_path($heroImageOriginal);
    [$heroWidth, $heroHeight] = @getimagesize($heroImagePath) ?: [420, 320];
    $heroSizes = [640, 960, 1280];
    $heroWebp = [];
    $heroPng = [];

    foreach ($heroSizes as $size) {
        $webpPath = public_path($heroImageBase . '-' . $size . '.webp');
        if (is_file($webpPath)) {
            $heroWebp[] = asset($heroImageBase . '-' . $size . '.webp') . ' ' . $size . 'w';
        }

        $pngPath = public_path($heroImageBase . '-' . $size . '.png');
        if (is_file($pngPath)) {
            $heroPng[] = asset($heroImageBase . '-' . $size . '.png') . ' ' . $size . 'w';
        }
    }

    $heroWebpSrcset = $heroWebp ? implode(', ', $heroWebp) : null;
    $heroPngSrcset = $heroPng ? implode(', ', $heroPng) : null;
    $heroSizesAttr = '(max-width: 768px) 70vw, 420px';
    $jsPath = public_path('assets/maintenance/maintenance.js');
    $cssPath = public_path('assets/maintenance/maintenance.css');
    $assetVersion = is_file($jsPath) ? substr(md5_file($jsPath), 0, 12) : time();
    $styleVersion = is_file($cssPath) ? substr(md5_file($cssPath), 0, 12) : $assetVersion;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="description" content="{{ __('maintenance.meta_description') }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} | 503</title>
    <link rel="canonical" href="{{ url('/') }}">
    <link rel="stylesheet" href="{{ asset('assets/maintenance/maintenance.css') }}?v={{ $styleVersion }}">
</head>

<body data-theme="light">
    <div class="backdrop" aria-hidden="true">
        <div class="glow"></div>
        <div class="mesh"></div>
        <div class="dust"></div>
        <div class="backdrop-grid"></div>
        <div class="backdrop-scan"></div>
        <div class="moon"></div>
        <div class="stars stars-back"></div>
        <div class="stars stars-front"></div>
        <div class="flare flare-one"></div>
        <div class="flare flare-two"></div>
        <div class="shard shard-one"></div>
        <div class="shard shard-two"></div>
        <div class="shard shard-three"></div>
        <div class="ribbon ribbon-one"></div>
        <div class="ribbon ribbon-two"></div>
        <svg class="cable cable-left" viewBox="0 0 520 240" fill="none" role="presentation" aria-hidden="true">
            <path d="M20 200C120 40 280 40 420 120C470 150 490 170 510 210" stroke="currentColor" stroke-width="3"
                stroke-linecap="round" stroke-dasharray="10 12" />
            <circle cx="510" cy="210" r="10" fill="currentColor" />
        </svg>
        <svg class="cable cable-right" viewBox="0 0 520 240" fill="none" role="presentation" aria-hidden="true">
            <path d="M10 30C140 120 300 120 430 40C470 20 490 10 510 10" stroke="currentColor" stroke-width="3"
                stroke-linecap="round" stroke-dasharray="10 12" />
            <circle cx="10" cy="30" r="10" fill="currentColor" />
        </svg>
        <div class="orb orb-one"></div>
        <div class="orb orb-two"></div>
        <div class="orb orb-three"></div>
    </div>

    <main class="page" data-maintenance role="main" data-server-now="{{ $serverNow }}"
        data-maintenance-start="{{ $startAt }}" data-maintenance-end="{{ $endAt }}"
        data-maintenance-note="{{ e($noteText ?? '') }}" data-maintenance-retry="{{ $retryAfter ?? '' }}"
        data-timezone="{{ $timezone }}">
        <header class="topbar">
            <div class="brand">
                <span class="brand-dot" aria-hidden="true"></span>
                <span>{{ $appName }}</span>
            </div>
            <div class="topbar-actions">
                <span class="status-pill" aria-live="polite" role="status" data-status>
                    {{ __('maintenance.status_label', ['code' => $statusCode]) }}
                </span>
                <button type="button" class="theme-toggle" data-theme-toggle
                    aria-pressed="false">{{ __('maintenance.theme_toggle') }}</button>
            </div>
        </header>

        <section class="hero tilt-card" data-tilt="1">
            <div class="hero-visual" data-hero-visual>
                <div class="visual-float">
                    <div class="visual-frame" data-hero-frame>
                        <picture class="hero-picture">
                            @if ($heroWebpSrcset)
                                <source type="image/webp" srcset="{{ $heroWebpSrcset }}" sizes="{{ $heroSizesAttr }}">
                            @endif
                            @if ($heroPngSrcset)
                                <source type="image/png" srcset="{{ $heroPngSrcset }}" sizes="{{ $heroSizesAttr }}">
                            @endif
                            <img src="{{ asset($heroImageOriginal) }}" alt="{{ __('maintenance.hero_alt') }}"
                                loading="eager"
                                decoding="async" fetchpriority="high" width="{{ $heroWidth }}"
                                height="{{ $heroHeight }}" draggable="false">
                        </picture>
                    </div>
                    <div class="visual-shadow" aria-hidden="true"></div>
                </div>
                <div class="visual-orbit orbit-one"></div>
                <div class="visual-orbit orbit-two"></div>
            </div>
            <div class="hero-content">
                <p class="eyebrow">{{ __('maintenance.eyebrow') }}</p>
                <h1>{{ $title }}</h1>
                <p class="lead">{{ $summary }}</p>
                <div class="status-row" role="status" aria-live="polite">
                    <span class="pill" data-status-message>{{ __('maintenance.status_message') }}</span>
                    <span class="pill subtle"
                        data-request-id>{{ __('maintenance.request_id', ['id' => $requestId]) }}</span>
                </div>
                <div class="timer-group">
                    <div class="timer-card">
                        <span class="timer-label">{{ __('maintenance.elapsed_time') }}</span>
                        <span class="timer-value" data-elapsed>00:00:00.000</span>
                    </div>
                    <div class="timer-card">
                        <span class="timer-label">{{ __('maintenance.remaining_time') }}</span>
                        <span class="timer-value" data-remaining>00:00:00.000</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid">
            <div class="panel tilt-card" data-tilt="0.6">
                <h2>{{ __('maintenance.schedule_title') }}</h2>
                <div class="time-grid">
                    <div class="time-item">
                        <span class="time-label">{{ __('maintenance.server_time', ['timezone' => $timezone]) }}</span>
                        <span class="time-value" data-time="now">--</span>
                    </div>
                    <div class="time-item">
                        <span class="time-label">{{ __('maintenance.start_time') }}</span>
                        <span class="time-value" data-time="start">{{ __('maintenance.not_set') }}</span>
                    </div>
                    <div class="time-item">
                        <span class="time-label">{{ __('maintenance.end_time') }}</span>
                        <span class="time-value" data-time="end">{{ __('maintenance.not_set') }}</span>
                    </div>
                    <div class="time-item" data-time="retry" aria-live="polite" aria-atomic="true"
                        style="{{ is_null($retryAfter) ? 'display:none;' : '' }}">
                        <span class="time-label">{{ __('maintenance.retry_after') }}</span>
                        <span class="time-value" data-time-value>{{ $retryAfter ?? '--' }}</span>
                    </div>
                </div>
            </div>

            <div class="panel panel-token tilt-card" data-tilt="0.5">
                <h2>{{ __('maintenance.access_title') }}</h2>
                <div class="field">
                    <label for="maintenance-token">{{ __('maintenance.token_label') }}</label>
                    <input id="maintenance-token" name="maintenance_token" type="password"
                        placeholder="{{ __('maintenance.token_placeholder') }}" autocomplete="one-time-code">
                </div>
                <div class="actions">
                    <button type="button" class="btn primary"
                        data-maintenance-submit>{{ __('maintenance.token_submit') }}</button>
                    <button type="button" class="btn">{{ __('maintenance.token_wait') }}</button>
                </div>
                <p class="token-feedback" data-token-feedback aria-live="polite"></p>
            </div>

            <div class="panel panel-note tilt-card" data-tilt="0.4">
                <h2>{{ __('maintenance.note_title') }}</h2>
                <div class="field">
                    <label for="maintenance-note">{{ __('maintenance.note_label') }}</label>
                    <div id="maintenance-note" class="note-box" data-maintenance-note-field></div>
                </div>
                <div class="hint">{{ __('maintenance.note_hint') }}</div>
            </div>

            <div class="panel mini tilt-card" data-tilt="0.4">
                <h2>{{ __('maintenance.recovery_title') }}</h2>
                <ul class="list">
                    @foreach (__('maintenance.recovery_items') as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            </div>
        </section>
    </main>

    <script src="{{ asset('assets/maintenance/maintenance.js') }}?v={{ $assetVersion }}" defer></script>
</body>

</html>
