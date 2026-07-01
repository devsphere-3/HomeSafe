<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'HomeSafe - Smart Lock')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    @stack('styles')
</head>
<body class="bg-gray-900 text-white min-h-screen">

    <nav class="bg-gray-800 border-b border-gray-700 px-6 py-3 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <a href="{{ route('home') }}" class="text-xl font-bold text-blue-400 flex items-center gap-2">
                <i class="fas fa-house-lock"></i>HomeSafe
            </a>
            <div class="flex gap-1">
                <a href="{{ route('home') }}"
                   class="px-3 py-2 rounded transition flex items-center gap-1 text-sm
                          {{ request()->routeIs('home') ? 'bg-blue-600 text-white' : 'hover:bg-gray-700 text-gray-300' }}">
                    <i class="fas fa-camera"></i><span class="hidden sm:inline">Recognition</span>
                </a>
                <a href="{{ route('enroll') }}"
                   class="px-3 py-2 rounded transition flex items-center gap-1 text-sm
                          {{ request()->routeIs('enroll') ? 'bg-blue-600 text-white' : 'hover:bg-gray-700 text-gray-300' }}">
                    <i class="fas fa-user-plus"></i><span class="hidden sm:inline">Enroll</span>
                </a>
                <a href="{{ route('users') }}"
                   class="px-3 py-2 rounded transition flex items-center gap-1 text-sm
                          {{ request()->routeIs('users') ? 'bg-blue-600 text-white' : 'hover:bg-gray-700 text-gray-300' }}">
                    <i class="fas fa-users"></i><span class="hidden sm:inline">Users</span>
                </a>
                <a href="{{ route('history') }}"
                   class="px-3 py-2 rounded transition flex items-center gap-1 text-sm
                          {{ request()->routeIs('history') ? 'bg-blue-600 text-white' : 'hover:bg-gray-700 text-gray-300' }}">
                    <i class="fas fa-clock-rotate-left"></i><span class="hidden sm:inline">History</span>
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto p-4 sm:p-6">
        @if(session('success'))
            <div class="bg-green-700 text-white px-4 py-3 rounded mb-4 flex items-center gap-2">
                <i class="fas fa-check-circle"></i>{{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="bg-red-700 text-white px-4 py-3 rounded mb-4 flex items-center gap-2">
                <i class="fas fa-exclamation-circle"></i>{{ session('error') }}
            </div>
        @endif
        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
