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
                    <span class="hidden sm:inline">Monitoring</span>
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

        // Init icon state
        applyTheme(localStorage.getItem('hs-theme') || 'dark');

        themeBtn.addEventListener('click', () => {
            const isDark = document.documentElement.classList.contains('dark');
            applyTheme(isDark ? 'light' : 'dark');
        });
    </script>

    @stack('scripts')
</body>
</html>
