@php
    $exception = $exception ?? null;
    $statusCode = $statusCode ?? null;

    if ($exception instanceof \Illuminate\Session\TokenMismatchException) {
        $statusCode = 419;
    } elseif ($exception instanceof \Illuminate\Auth\Access\AuthorizationException) {
        $statusCode = 403;
    } elseif ($exception instanceof \Spatie\Permission\Exceptions\UnauthorizedException) {
        $statusCode = 403;
    } elseif ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
        $statusCode = 404;
    }

    if (! is_int($statusCode)) {
        $statusCode = $exception && method_exists($exception, 'getStatusCode')
            ? (int) $exception->getStatusCode()
            : (int) http_response_code();
    }

    if ($statusCode < 400) {
        $statusCode = 500;
    }

    $catalog = trans('errors.catalog');
    $exceptionHints = trans('errors.exception_hints');

    $meta = $catalog[$statusCode] ?? $catalog[500];
    $title = $meta['title'];
    $summary = $meta['summary'];
    $why = $meta['why'];
    $recovery = $meta['recovery'];

    if ($exception) {
        foreach ($exceptionHints as $class => $hint) {
            if ($exception instanceof $class) {
                $why = array_merge($hint['why'], $why);
                $recovery = array_merge($hint['recovery'], $recovery);
                break;
            }
        }
    }

    $why = array_values(array_unique($why));
    $recovery = array_values(array_unique($recovery));

    $severity = match (true) {
        $statusCode >= 500 => 'critical',
        in_array($statusCode, [401, 403, 419, 429], true) => 'warning',
        $statusCode === 404 => 'info',
        default => 'critical',
    };

    $severityLabel = trans('errors.severity.'.$severity) ?: trans('errors.severity.critical');

    $request = request();
    $requestId = $requestId ?? $request->headers->get('X-Request-Id');
    $requestId = is_string($requestId) && $requestId !== '' ? $requestId : __('errors.labels.na');
    $timestamp = now()->toIso8601String();
    $path = '/' . ltrim($request->path(), '/');
    $method = $request->method();
    $retryAfter = $request->headers->get('Retry-After');

    $clientIp = $request->getClientIp();
    $clientIps = $request->getClientIps();
    $proxyChain = count($clientIps) > 1 ? implode(' -> ', $clientIps) : null;

    $uaRaw = (string) ($request->userAgent() ?? '');
    $uaRedacted = $uaRaw !== '' ? preg_replace('/\b\d+[\.\w-]*\b/', 'x', $uaRaw) : '';
    $uaRedacted = $uaRedacted !== '' ? \Illuminate\Support\Str::limit($uaRedacted, 120) : __('errors.labels.unknown');

    $canSeeDetails = false;
    if (app()->environment(['local', 'staging'])) {
        try {
            $user = auth()->user();
            $canSeeDetails = $user && method_exists($user, 'isDeveloper') && $user->isDeveloper();
        } catch (\Throwable) {
            $canSeeDetails = false;
        }
    }

    $exceptionClass = $exception ? $exception::class : null;
    $exceptionMessage = null;
    $exceptionFile = null;
    $exceptionLine = null;
    $tracePreview = [];

    if ($canSeeDetails && $exception) {
        $exceptionMessage = (string) ($exception->getMessage() ?? '');
        $exceptionMessage = $exceptionMessage !== '' ? \Illuminate\Support\Str::limit($exceptionMessage, 240) : 'n/a';

        $exceptionFile = $exception->getFile();
        $basePath = base_path();
        if (is_string($exceptionFile) && str_starts_with($exceptionFile, $basePath)) {
            $exceptionFile = ltrim(substr($exceptionFile, strlen($basePath)), DIRECTORY_SEPARATOR);
        }
        $exceptionLine = $exception->getLine();

        $trace = $exception->getTrace();
        foreach (array_slice($trace, 0, 8) as $frame) {
            $location = ($frame['file'] ?? '[internal]') . ':' . ($frame['line'] ?? '');
            $function = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $tracePreview[] = trim($function . ' at ' . $location);
        }
    }

    $showLogin = in_array($statusCode, [401, 403, 419], true);
    $loginUrl = \Illuminate\Support\Facades\Route::has('filament.admin.auth.login')
        ? route('filament.admin.auth.login')
        : url('/admin/login');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>{{ $title }} | {{ __('errors.labels.error') }} {{ $statusCode }}</title>
        <link rel="stylesheet" href="{{ asset('assets/errors/error.css') }}">
    </head>
    <body data-severity="{{ $severity }}">
        <div class="ambient">
            <div class="starfield" aria-hidden="true"></div>
            <div class="aurora one" aria-hidden="true"></div>
            <div class="aurora two" aria-hidden="true"></div>
            <div class="nebula" aria-hidden="true"></div>
            <div class="ring" aria-hidden="true"></div>
            <div class="beam"></div>
            <div class="grid-3d"></div>
            <div class="orb one"></div>
            <div class="orb two"></div>
            <div class="orb three"></div>
        </div>
        <main class="shell" role="main">
            <section class="hero tilt-card" data-tilt="1">
                <div class="tagline">
                    <span class="pulse" aria-hidden="true"></span>
                    {{ $severityLabel }}
                </div>
                <div class="code">{{ $statusCode }}</div>
                <h1 class="title">{{ $title }}</h1>
                <p class="summary" role="alert">{{ $summary }}</p>
                <div class="status-bar">
                    <span>{{ __('errors.labels.incident_id', ['id' => $requestId]) }}</span>
                    <span class="status-pill">{{ $severity }}</span>
                    <button type="button" class="btn toggle" data-theme-toggle
                        aria-pressed="false">{{ __('errors.labels.toggle_theme') }}</button>
                </div>
                <div class="actions">
                    <a class="btn primary" href="{{ url('/') }}">{{ __('errors.labels.back_home') }}</a>
                    <button type="button" class="btn ghost" data-action="back">{{ __('errors.labels.back') }}</button>
                    <button type="button" class="btn ghost"
                        data-action="reload">{{ __('errors.labels.reload') }}</button>
                    @if ($showLogin)
                        <a class="btn" href="{{ $loginUrl }}">{{ __('errors.labels.login') }}</a>
                    @endif
                </div>
            </section>

            <section class="grid">
                <div class="panel tilt-card" data-tilt="0.6">
                    <h2>{{ __('errors.labels.why_title') }}</h2>
                    <ul>
                        @foreach ($why as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="panel tilt-card" data-tilt="0.5">
                    <h2>{{ __('errors.labels.recovery_title') }}</h2>
                    <ol>
                        @foreach ($recovery as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ol>
                </div>

                <div class="panel tilt-card" data-tilt="0.4">
                    <h2>{{ __('errors.labels.request_preview') }}</h2>
                    <dl class="meta">
                        <div class="meta-row">
                            <dt>{{ __('errors.labels.request_id') }}</dt>
                            <dd>{{ $requestId }}</dd>
                        </div>
                        <div class="meta-row">
                            <dt>{{ __('errors.labels.timestamp') }}</dt>
                            <dd>{{ $timestamp }}</dd>
                        </div>
                        <div class="meta-row">
                            <dt>{{ __('errors.labels.path') }}</dt>
                            <dd>{{ $method }} {{ $path }}</dd>
                        </div>
                        <div class="meta-row">
                            <dt>{{ __('errors.labels.status') }}</dt>
                            <dd>{{ $statusCode }}</dd>
                        </div>
                        @if ($retryAfter)
                            <div class="meta-row">
                                <dt>{{ __('errors.labels.retry_after') }}</dt>
                                <dd>{{ $retryAfter }}</dd>
                            </div>
                        @endif
                    </dl>
                    <div class="actions is-compact">
                        <button type="button" class="btn"
                            data-copy-request-id="{{ $requestId }}">{{ __('errors.labels.copy_request_id') }}</button>
                    </div>

                    @if ($canSeeDetails)
                        <div class="note">{{ __('errors.labels.advanced_view') }}</div>
                        <dl class="meta">
                            <div class="meta-row">
                                <dt>{{ __('errors.labels.client_ip') }}</dt>
                                <dd>{{ $clientIp ?? 'unknown' }}</dd>
                            </div>
                            <div class="meta-row">
                                <dt>{{ __('errors.labels.proxy_chain') }}</dt>
                                <dd>{{ $proxyChain ?? 'none' }}</dd>
                            </div>
                            <div class="meta-row">
                                <dt>{{ __('errors.labels.user_agent') }}</dt>
                                <dd>{{ $uaRedacted }}</dd>
                            </div>
                        </dl>
                        <div class="note">
                            {{ __('errors.labels.proxy_note') }}
                        </div>
                    @endif
                </div>

                @if ($canSeeDetails)
                    <div class="panel dev-details tilt-card" data-tilt="0.3">
                        <details>
                            <summary>{{ __('errors.labels.developer_details') }}</summary>
                            <div>{{ __('errors.labels.exception') }}: {{ $exceptionClass ?? 'n/a' }}</div>
                            <div>{{ __('errors.labels.message') }}: {{ $exceptionMessage ?? 'n/a' }}</div>
                            <div>{{ __('errors.labels.location') }}: {{ $exceptionFile ?? 'n/a' }}:{{ $exceptionLine ?? 'n/a' }}</div>
                            @if (! empty($tracePreview))
                                <div class="trace">{{ implode("\n", $tracePreview) }}</div>
                            @endif
                        </details>
                    </div>
                @endif
            </section>
        </main>
        <script src="{{ asset('assets/errors/error.js') }}" defer></script>
    </body>
</html>
