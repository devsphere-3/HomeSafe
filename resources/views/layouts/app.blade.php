<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'HomeSafe')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script>
        // Apply saved theme before paint to avoid flash
        (function(){
            const t = localStorage.getItem('hs-theme') || 'dark';
            document.documentElement.classList.toggle('dark', t === 'dark');
        })();
    </script>
    <style>
        /* ── Base ── */
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; }

        /* ── Glass utility ── */
        .glass {
            background: rgba(15, 28, 50, 0.72);
            backdrop-filter: blur(24px) saturate(1.4);
            -webkit-backdrop-filter: blur(24px) saturate(1.4);
            border: 1px solid rgba(255, 255, 255, 0.10);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.28), inset 0 1px 0 rgba(255,255,255,0.07);
        }
        /* Light mode glass — warm slate tint, NOT blinding white */
        html:not(.dark) .glass {
            background: rgba(226, 232, 245, 0.70);
            border: 1px solid rgba(148, 163, 184, 0.25);
            box-shadow: 0 4px 20px rgba(15,23,42,0.10), inset 0 1px 0 rgba(255,255,255,0.5);
        }
        .glass-sm {
            background: rgba(15, 28, 50, 0.60);
            backdrop-filter: blur(18px) saturate(1.3);
            -webkit-backdrop-filter: blur(18px) saturate(1.3);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.22), inset 0 1px 0 rgba(255,255,255,0.05);
        }
        html:not(.dark) .glass-sm {
            background: rgba(210, 220, 237, 0.65);
            border: 1px solid rgba(148,163,184,0.20);
            box-shadow: 0 2px 10px rgba(15,23,42,0.08), inset 0 1px 0 rgba(255,255,255,0.45);
        }

        /* ── Noise texture on glass ── */
        .glass::before, .glass-sm::before {
            content: '';
            position: absolute; inset: 0;
            border-radius: inherit;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 0;
        }

        /* ── Nav glass ── */
        .nav-glass {
            background: rgba(8, 17, 33, 0.82);
            backdrop-filter: blur(24px) saturate(1.4);
            -webkit-backdrop-filter: blur(24px) saturate(1.4);
            border-bottom: 1px solid rgba(255, 255, 255, 0.07);
            box-shadow: 0 1px 0 rgba(255,255,255,0.04);
        }
        /* Light nav — steel blue-grey, not harsh white */
        html:not(.dark) .nav-glass {
            background: rgba(215, 225, 240, 0.88);
            backdrop-filter: blur(20px) saturate(1.3);
            -webkit-backdrop-filter: blur(20px) saturate(1.3);
            border-bottom: 1px solid rgba(148,163,184,0.22);
            box-shadow: 0 1px 0 rgba(255,255,255,0.35);
        }

        /* ── Nav link ── */
        .nav-link {
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 0.8125rem;
            font-weight: 500;
            color: #94a3b8;
            display: flex; align-items: center; gap: 6px;
            transition: all 0.2s ease;
            position: relative;
        }
        .nav-link:hover {
            color: #f1f5f9;
            background: rgba(255,255,255,0.07);
        }
        html:not(.dark) .nav-link { color: #64748b; }
        html:not(.dark) .nav-link:hover { color: #0f172a; background: rgba(0,0,0,0.05); }
        .nav-link.active {
            color: #3b82f6;
            background: rgba(59,130,246,0.12);
        }
        html:not(.dark) .nav-link.active {
            color: #2563eb;
            background: rgba(37,99,235,0.1);
        }

        /* ── Background ── */
        .page-bg {
            background-color: #0a1628;
            min-height: 100vh;
        }
        /* Light bg — muted steel slate, not pure white */
        html:not(.dark) .page-bg {
            background-color: #dce4f0;
        }

        /* ── Abstract background shapes ── */
        .bg-shape {
            position: fixed;
            border-radius: 50%;
            filter: blur(90px);
            opacity: 0.10;
            pointer-events: none;
            z-index: 0;
        }
        html:not(.dark) .bg-shape { opacity: 0.09; }

        /* ── Theme toggle ── */
        .theme-btn {
            width: 36px; height: 36px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            color: #94a3b8;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .theme-btn:hover {
            background: rgba(255,255,255,0.12);
            color: #f1f5f9;
            transform: scale(1.05);
        }
        html:not(.dark) .theme-btn {
            background: rgba(0,0,0,0.04);
            border-color: rgba(0,0,0,0.08);
            color: #64748b;
        }
        html:not(.dark) .theme-btn:hover { background: rgba(0,0,0,0.08); color: #0f172a; }

        /* ── Alert flash ── */
        .alert-success {
            background: rgba(16,185,129,0.15);
            border: 1px solid rgba(16,185,129,0.3);
            color: #6ee7b7;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 0.875rem;
            display: flex; align-items: center; gap: 8px;
        }
        .alert-error {
            background: rgba(239,68,68,0.15);
            border: 1px solid rgba(239,68,68,0.3);
            color: #fca5a5;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 0.875rem;
            display: flex; align-items: center; gap: 8px;
        }

        /* ── Scrollbar ── */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.2); border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(148,163,184,0.4); }

        /* ── Text colors ── */
        .text-primary   { color: #f1f5f9; }
        .text-secondary { color: #94a3b8; }
        /* Light text — dark slate so readable on grey bg */
        html:not(.dark) .text-primary   { color: #0f172a; }
        html:not(.dark) .text-secondary { color: #4a5568; }
    </style>
    @stack('styles')
</head>
<body class="page-bg text-primary">

    <!-- Background shapes -->
    <div class="bg-shape w-96 h-96 bg-blue-500"    style="top:-8rem;left:-8rem;"></div>
    <div class="bg-shape w-80 h-80 bg-indigo-500"  style="bottom:10%;right:-6rem;"></div>
    <div class="bg-shape w-64 h-64 bg-sky-400"     style="top:40%;left:30%;"></div>

    <!-- Navbar -->
    <nav class="nav-glass sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 h-14 flex items-center justify-between">

            <!-- Logo -->
            <a href="{{ route('home') }}" class="flex items-center gap-2.5 group">
                <div class="w-8 h-8 rounded-lg bg-blue-500 flex items-center justify-center text-white text-sm font-bold shadow-lg shadow-blue-500/20 group-hover:scale-105 transition-transform">
                    <i class="fas fa-house-lock text-xs"></i>
                </div>
                <span class="font-bold text-[15px] text-primary tracking-tight">HomeSafe</span>
            </a>

            <!-- Nav links -->
            <div class="flex items-center gap-1">
                <a href="{{ route('home') }}" class="nav-link {{ request()->routeIs('home') ? 'active' : '' }}">
                    <i class="fas fa-video text-[11px]"></i>
                    <span class="hidden sm:inline">Dashboard</span>
                </a>
                <a href="{{ route('cameras') }}" class="nav-link {{ request()->routeIs('cameras') ? 'active' : '' }}">
                    <i class="fas fa-expand text-[11px]"></i>
                    <span class="hidden sm:inline">Live View</span>
                </a>
                <a href="{{ route('enroll') }}" class="nav-link {{ request()->routeIs('enroll') ? 'active' : '' }}">
                    <i class="fas fa-user-plus text-[11px]"></i>
                    <span class="hidden sm:inline">Enroll</span>
                </a>
                <a href="{{ route('users') }}" class="nav-link {{ request()->routeIs('users') ? 'active' : '' }}">
                    <i class="fas fa-users text-[11px]"></i>
                    <span class="hidden sm:inline">Pengguna</span>
                </a>
                <a href="{{ route('history') }}" class="nav-link {{ request()->routeIs('history') ? 'active' : '' }}">
                    <i class="fas fa-clock-rotate-left text-[11px]"></i>
                    <span class="hidden sm:inline">Riwayat</span>
                </a>
                {{-- Database hanya lewat Ctrl+D — tidak tampil di navbar publik --}}
                @if(request()->routeIs('database'))
                <a href="{{ route('database') }}" class="nav-link active">
                    <i class="fas fa-database text-[11px]"></i>
                    <span class="hidden sm:inline">Database</span>
                </a>
                @endif
            </div>

            <!-- Theme toggle -->
            <button class="theme-btn" id="theme-toggle" title="Toggle theme" aria-label="Toggle dark/light mode">
                <i class="fas fa-moon text-xs dark-icon"></i>
                <i class="fas fa-sun text-xs light-icon hidden"></i>
            </button>
        </div>
    </nav>

    <!-- Main content -->
    <main class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 py-6">
        @if(session('success'))
            <div class="alert-success mb-4">
                <i class="fas fa-check-circle"></i>{{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="alert-error mb-4">
                <i class="fas fa-exclamation-circle"></i>{{ session('error') }}
            </div>
        @endif
        @yield('content')
    </main>

    <!-- Theme toggle script -->
    <script>
        const themeBtn  = document.getElementById('theme-toggle');
        const darkIcon  = themeBtn.querySelector('.dark-icon');
        const lightIcon = themeBtn.querySelector('.light-icon');

        function applyTheme(t) {
            const isDark = t === 'dark';
            document.documentElement.classList.toggle('dark', isDark);
            darkIcon.classList.toggle('hidden',  isDark);
            lightIcon.classList.toggle('hidden', !isDark);
            localStorage.setItem('hs-theme', t);
        }
        applyTheme(localStorage.getItem('hs-theme') || 'dark');
        themeBtn.addEventListener('click', () => {
            const isDark = document.documentElement.classList.contains('dark');
            applyTheme(isDark ? 'light' : 'dark');
        });
    </script>

    <!-- ══════════════════════════════════════════════════════════════
         ADMIN MODAL — Database Access (Ctrl+D)
         Password disimpan di .env → ADMIN_DB_PASSWORD
         ══════════════════════════════════════════════════════════════ -->
    <style>
        #admin-modal-backdrop {
            position: fixed; inset: 0; z-index: 9999;
            background: rgba(0,0,0,0.65);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: flex; align-items: center; justify-content: center;
            opacity: 0; pointer-events: none;
            transition: opacity 0.2s ease;
        }
        #admin-modal-backdrop.open { opacity: 1; pointer-events: auto; }

        #admin-modal {
            background: rgba(10,22,40,0.95);
            border: 1px solid rgba(59,130,246,0.25);
            border-radius: 18px;
            padding: 2rem;
            width: 100%; max-width: 380px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.6), 0 0 0 1px rgba(255,255,255,0.05);
            transform: scale(0.95) translateY(8px);
            transition: transform 0.2s ease;
        }
        #admin-modal-backdrop.open #admin-modal {
            transform: scale(1) translateY(0);
        }
        html:not(.dark) #admin-modal {
            background: rgba(220,228,240,0.97);
            border-color: rgba(59,130,246,0.2);
            box-shadow: 0 20px 60px rgba(15,23,42,0.25);
        }

        .admin-input {
            width: 100%;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 10px;
            padding: 0.65rem 1rem 0.65rem 2.5rem;
            color: #f1f5f9;
            font-size: 0.875rem;
            font-family: inherit;
            outline: none;
            transition: border-color 0.15s;
        }
        html:not(.dark) .admin-input {
            background: rgba(255,255,255,0.7);
            border-color: rgba(148,163,184,0.3);
            color: #0f172a;
        }
        .admin-input:focus { border-color: #3b82f6; }
        .admin-input.error { border-color: #ef4444; }

        .admin-btn {
            width: 100%;
            padding: 0.65rem;
            border-radius: 10px;
            font-size: 0.875rem; font-weight: 600;
            cursor: pointer; border: none;
            background: #3b82f6; color: #fff;
            transition: background 0.15s, transform 0.1s;
            font-family: inherit;
        }
        .admin-btn:hover { background: #2563eb; }
        .admin-btn:active { transform: scale(0.98); }

        .admin-hint {
            font-size: 0.68rem; color: #475569; text-align: center; margin-top: 0.75rem;
        }
        html:not(.dark) .admin-hint { color: #64748b; }

        /* shake animation on wrong password */
        @keyframes adminShake {
            0%,100%{transform:translateX(0)}
            20%{transform:translateX(-8px)}
            40%{transform:translateX(8px)}
            60%{transform:translateX(-6px)}
            80%{transform:translateX(6px)}
        }
        .admin-shake { animation: adminShake 0.4s ease; }
    </style>

    <!-- Modal HTML -->
    <div id="admin-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="admin-modal-title">
        <div id="admin-modal">
            <!-- Header -->
            <div class="flex items-center gap-3 mb-5">
                <div class="w-10 h-10 rounded-xl bg-blue-500/15 border border-blue-500/25
                            flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-database text-blue-400"></i>
                </div>
                <div>
                    <p id="admin-modal-title" class="font-bold text-sm"
                       style="color:#f1f5f9;">Panel Admin</p>
                    <p class="text-xs" style="color:#64748b;">Akses Database HomeSafe</p>
                </div>
                <button id="admin-modal-close"
                        class="ml-auto w-7 h-7 rounded-lg flex items-center justify-center
                               text-slate-500 hover:text-slate-300 hover:bg-white/8 transition"
                        aria-label="Tutup">
                    <i class="fas fa-times text-xs"></i>
                </button>
            </div>

            <!-- Form -->
            <form id="admin-form" autocomplete="off" novalidate>
                <div class="relative mb-3">
                    <i class="fas fa-lock absolute left-3 top-1/2 -translate-y-1/2
                               text-slate-500 text-xs pointer-events-none"></i>
                    <input id="admin-password-input"
                           type="password"
                           class="admin-input"
                           placeholder="Kata sandi admin…"
                           aria-label="Kata sandi admin"
                           autofocus />
                </div>
                <p id="admin-error-msg"
                   class="text-xs text-red-400 mb-3 hidden flex items-center gap-1">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>Kata sandi salah.</span>
                </p>
                <button type="submit" class="admin-btn">
                    <i class="fas fa-arrow-right-to-bracket mr-1.5"></i>Masuk ke Database
                </button>
            </form>

            <p class="admin-hint">
                <i class="fas fa-keyboard mr-1"></i>
                Tekan <kbd class="px-1.5 py-0.5 rounded bg-white/10 text-[10px] font-mono">Ctrl+D</kbd>
                kapan saja untuk membuka panel ini
            </p>
        </div>
    </div>

    <script>
    (() => {
        // Password dikonfigurasi di .env → ADMIN_DB_PASSWORD
        // Dikirim ke view via data attribute pada body agar tidak hardcode di JS
        const ADMIN_PASSWORD = '{{ config("app.admin_db_password", env("ADMIN_DB_PASSWORD", "homesafe2026")) }}';
        const DB_ROUTE       = '{{ route("database") }}';

        const backdrop  = document.getElementById('admin-modal-backdrop');
        const modal     = document.getElementById('admin-modal');
        const form      = document.getElementById('admin-form');
        const input     = document.getElementById('admin-password-input');
        const errMsg    = document.getElementById('admin-error-msg');
        const closeBtn  = document.getElementById('admin-modal-close');

        function openModal() {
            backdrop.classList.add('open');
            input.value = '';
            errMsg.classList.add('hidden');
            input.classList.remove('error');
            // sedikit delay agar transisi selesai dulu
            setTimeout(() => input.focus(), 80);
        }

        function closeModal() {
            backdrop.classList.remove('open');
            input.value = '';
        }

        // Ctrl+D — buka modal dari mana saja
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey && e.key === 'd') {
                e.preventDefault();   // cegah browser bookmark
                // Kalau sudah di halaman database, tidak perlu modal
                if (window.location.pathname === new URL(DB_ROUTE).pathname) return;
                openModal();
            }
            if (e.key === 'Escape') closeModal();
        });

        // Tutup saat klik backdrop (di luar modal)
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) closeModal();
        });

        closeBtn.addEventListener('click', closeModal);

        // Submit form
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const val = input.value.trim();

            if (val === ADMIN_PASSWORD) {
                // Benar — navigasi ke database
                closeModal();
                window.location.href = DB_ROUTE;
            } else {
                // Salah — shake + pesan error
                input.classList.add('error');
                errMsg.classList.remove('hidden');
                modal.classList.remove('admin-shake');
                void modal.offsetWidth; // reflow untuk restart animasi
                modal.classList.add('admin-shake');
                input.select();
                setTimeout(() => modal.classList.remove('admin-shake'), 500);
            }
        });

        // Reset error saat mulai mengetik lagi
        input.addEventListener('input', () => {
            input.classList.remove('error');
            errMsg.classList.add('hidden');
        });
    })();
    </script>

    @stack('scripts')
</body>
</html>
