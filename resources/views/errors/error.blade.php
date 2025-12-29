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

    $catalog = [
        401 => [
            'title' => 'Authentication required',
            'summary' => 'Anda perlu login untuk melanjutkan.',
            'why' => [
                'Sesi login belum tersedia atau sudah berakhir.',
                'Permintaan membutuhkan identitas pengguna.',
            ],
            'recovery' => [
                'Login kembali, lalu ulangi aksi Anda.',
                'Gunakan akun dengan akses yang sesuai.',
            ],
        ],
        403 => [
            'title' => 'Akses ditolak',
            'summary' => 'Anda tidak memiliki izin untuk membuka halaman ini.',
            'why' => [
                'Role Anda tidak memiliki permission yang diperlukan.',
                'Akun sedang dibatasi atau statusnya tidak aktif.',
            ],
            'recovery' => [
                'Login dengan akun yang memiliki akses.',
                'Hubungi admin untuk meminta izin.',
                'Kembali ke halaman aman.',
            ],
        ],
        404 => [
            'title' => 'Halaman tidak ditemukan',
            'summary' => 'Alamat yang Anda buka tidak tersedia.',
            'why' => [
                'URL salah ketik atau sudah tidak berlaku.',
                'Resource sudah dipindahkan atau dihapus.',
            ],
            'recovery' => [
                'Periksa kembali alamat URL.',
                'Kembali ke beranda dan coba dari menu.',
            ],
        ],
        419 => [
            'title' => 'Sesi kedaluwarsa',
            'summary' => 'Token keamanan tidak valid atau sesi sudah habis.',
            'why' => [
                'Sesi Anda berakhir karena tidak aktif.',
                'Form dikirim ulang dari tab lama.',
            ],
            'recovery' => [
                'Muat ulang halaman, lalu coba lagi.',
                'Login kembali jika diminta.',
            ],
        ],
        429 => [
            'title' => 'Terlalu banyak permintaan',
            'summary' => 'Server membatasi request untuk sementara.',
            'why' => [
                'Anda melakukan terlalu banyak aksi dalam waktu singkat.',
                'Batas rate limit untuk keamanan telah tercapai.',
            ],
            'recovery' => [
                'Tunggu beberapa saat sebelum mencoba lagi.',
                'Kurangi frekuensi aksi berulang.',
            ],
        ],
        500 => [
            'title' => 'Terjadi kesalahan',
            'summary' => 'Server mengalami masalah internal.',
            'why' => [
                'Terjadi exception yang belum tertangani.',
                'Konfigurasi atau dependency tidak sesuai.',
            ],
            'recovery' => [
                'Coba muat ulang halaman.',
                'Jika berulang, hubungi admin.',
            ],
        ],
        503 => [
            'title' => 'Layanan tidak tersedia',
            'summary' => 'Server sedang dalam pemeliharaan atau overload.',
            'why' => [
                'Pemeliharaan terjadwal sedang berlangsung.',
                'Kapasitas server penuh sementara.',
            ],
            'recovery' => [
                'Coba beberapa menit lagi.',
                'Periksa status layanan jika tersedia.',
            ],
        ],
    ];

    $exceptionHints = [
        \Illuminate\Database\QueryException::class => [
            'why' => [
                'Database query gagal atau schema belum siap.',
            ],
            'recovery' => [
                'Periksa koneksi database dan jalankan migration.',
            ],
        ],
        \Illuminate\Auth\AuthenticationException::class => [
            'why' => [
                'Token sesi tidak dikenali oleh server.',
            ],
            'recovery' => [
                'Login ulang untuk membuat sesi baru.',
            ],
        ],
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class => [
            'why' => [
                'Route yang diakses tidak terdaftar.',
            ],
            'recovery' => [
                'Gunakan menu navigasi resmi aplikasi.',
            ],
        ],
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class => [
            'why' => [
                'Metode HTTP tidak diizinkan untuk endpoint ini.',
            ],
            'recovery' => [
                'Ulangi aksi dari UI yang benar.',
            ],
        ],
        \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException::class => [
            'why' => [
                'Server sedang dalam mode maintenance atau overload.',
            ],
            'recovery' => [
                'Coba ulang setelah beberapa saat.',
            ],
        ],
        \Illuminate\Session\TokenMismatchException::class => [
            'why' => [
                'Token keamanan tidak cocok dengan sesi aktif.',
            ],
            'recovery' => [
                'Muat ulang halaman agar token baru dibuat.',
            ],
        ],
    ];

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

    $severityLabel = [
        'critical' => 'Critical Incident',
        'warning' => 'Guarded Response',
        'info' => 'Informational',
    ][$severity] ?? 'Critical Incident';

    $request = request();
    $requestId = $requestId ?? $request->headers->get('X-Request-Id');
    $requestId = is_string($requestId) && $requestId !== '' ? $requestId : 'n/a';
    $timestamp = now()->toIso8601String();
    $path = '/' . ltrim($request->path(), '/');
    $method = $request->method();
    $retryAfter = $request->headers->get('Retry-After');

    $clientIp = $request->getClientIp();
    $clientIps = $request->getClientIps();
    $proxyChain = count($clientIps) > 1 ? implode(' -> ', $clientIps) : null;

    $uaRaw = (string) ($request->userAgent() ?? '');
    $uaRedacted = $uaRaw !== '' ? preg_replace('/\b\d+[\.\w-]*\b/', 'x', $uaRaw) : '';
    $uaRedacted = $uaRedacted !== '' ? \Illuminate\Support\Str::limit($uaRedacted, 120) : 'unknown';

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
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>{{ $title }} | Error {{ $statusCode }}</title>
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
                    <span>Incident ID: {{ $requestId }}</span>
                    <span class="status-pill">{{ $severity }}</span>
                    <button type="button" class="btn toggle" data-theme-toggle aria-pressed="false">Toggle theme</button>
                </div>
                <div class="actions">
                    <a class="btn primary" href="{{ url('/') }}">Beranda</a>
                    <button type="button" class="btn ghost" data-action="back">Kembali</button>
                    <button type="button" class="btn ghost" data-action="reload">Muat ulang</button>
                    @if ($showLogin)
                        <a class="btn" href="{{ $loginUrl }}">Login</a>
                    @endif
                </div>
            </section>

            <section class="grid">
                <div class="panel tilt-card" data-tilt="0.6">
                    <h2>Mengapa ini terjadi</h2>
                    <ul>
                        @foreach ($why as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="panel tilt-card" data-tilt="0.5">
                    <h2>Langkah pemulihan</h2>
                    <ol>
                        @foreach ($recovery as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ol>
                </div>

                <div class="panel tilt-card" data-tilt="0.4">
                    <h2>Request / Client Preview</h2>
                    <dl class="meta">
                        <div class="meta-row">
                            <dt>Request ID</dt>
                            <dd>{{ $requestId }}</dd>
                        </div>
                        <div class="meta-row">
                            <dt>Timestamp</dt>
                            <dd>{{ $timestamp }}</dd>
                        </div>
                        <div class="meta-row">
                            <dt>Path</dt>
                            <dd>{{ $method }} {{ $path }}</dd>
                        </div>
                        <div class="meta-row">
                            <dt>Status</dt>
                            <dd>{{ $statusCode }}</dd>
                        </div>
                        @if ($retryAfter)
                            <div class="meta-row">
                                <dt>Retry After</dt>
                                <dd>{{ $retryAfter }}</dd>
                            </div>
                        @endif
                    </dl>
                    <div class="actions is-compact">
                        <button type="button" class="btn" data-copy-request-id="{{ $requestId }}">Copy Request ID</button>
                    </div>

                    @if ($canSeeDetails)
                        <div class="note">Advanced view (developer only).</div>
                        <dl class="meta">
                            <div class="meta-row">
                                <dt>Client IP (observed)</dt>
                                <dd>{{ $clientIp ?? 'unknown' }}</dd>
                            </div>
                            <div class="meta-row">
                                <dt>Proxy chain (trusted)</dt>
                                <dd>{{ $proxyChain ?? 'none' }}</dd>
                            </div>
                            <div class="meta-row">
                                <dt>User agent (redacted)</dt>
                                <dd>{{ $uaRedacted }}</dd>
                            </div>
                        </dl>
                        <div class="note">
                            Network IP tidak dapat diketahui dari request biasa. Proxy chain hanya tampil jika reverse proxy terpercaya dikonfigurasi.
                        </div>
                    @endif
                </div>

                @if ($canSeeDetails)
                    <div class="panel dev-details tilt-card" data-tilt="0.3">
                        <details>
                            <summary>Developer details</summary>
                            <div>Exception: {{ $exceptionClass ?? 'n/a' }}</div>
                            <div>Message: {{ $exceptionMessage ?? 'n/a' }}</div>
                            <div>Location: {{ $exceptionFile ?? 'n/a' }}:{{ $exceptionLine ?? 'n/a' }}</div>
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
