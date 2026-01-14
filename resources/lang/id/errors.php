<?php

return [
    'catalog' => [
        401 => [
            'title' => 'Autentikasi diperlukan',
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
    ],
    'exception_hints' => [
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
    ],
    'severity' => [
        'critical' => 'Insiden Kritis',
        'warning' => 'Respons Terjaga',
        'info' => 'Informasional',
    ],
    'labels' => [
        'error' => 'Kesalahan',
        'unknown' => 'tidak diketahui',
        'na' => 'tidak ada',
        'severity' => 'Keparahan',
        'timestamp' => 'Stempel waktu',
        'request_id' => 'ID Permintaan',
        'path' => 'Path',
        'support' => 'Butuh bantuan? Hubungi dukungan.',
        'back_home' => 'Kembali ke beranda',
        'try_again' => 'Coba lagi',
        'incident_id' => 'ID Insiden: :id',
        'severity_chip' => 'Keparahan',
        'toggle_theme' => 'Ganti tema',
        'back' => 'Kembali',
        'reload' => 'Muat ulang',
        'login' => 'Login',
        'why_title' => 'Mengapa ini terjadi',
        'recovery_title' => 'Langkah pemulihan',
        'request_preview' => 'Pratinjau Request / Klien',
        'status' => 'Status',
        'retry_after' => 'Coba lagi setelah',
        'copy_request_id' => 'Salin ID Permintaan',
        'advanced_view' => 'Tampilan lanjutan (khusus developer).',
        'client_ip' => 'IP klien (terdeteksi)',
        'proxy_chain' => 'Rantai proxy (tepercaya)',
        'user_agent' => 'User agent (disamarkan)',
        'proxy_note' => 'IP jaringan tidak selalu terlihat dari request biasa. Rantai proxy hanya tampil jika reverse proxy tepercaya dikonfigurasi.',
        'developer_details' => 'Detail developer',
        'exception' => 'Eksepsi',
        'message' => 'Pesan',
        'location' => 'Lokasi',
    ],
];
