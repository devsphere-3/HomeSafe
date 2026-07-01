@extends('layouts.app')

@section('title', 'Riwayat Akses - HomeSafe')

@php
    $backendUrl = config('app.backend_url', env('BACKEND_URL', 'http://127.0.0.1:5001'));
@endphp

@push('styles')
<style>
.entry-card {
    background: #1f2937;
    border: 1px solid #374151;
    border-radius: 10px;
    overflow: hidden;
    transition: border-color .2s, transform .2s;
    display: flex; flex-direction: column;
}
.entry-card:hover { border-color: #4b5563; transform: translateY(-2px); }
.entry-card img {
    width: 100%; aspect-ratio: 4/3;
    object-fit: cover; background: #111;
}
.entry-card .no-img {
    width: 100%; aspect-ratio: 4/3;
    display: flex; align-items: center; justify-content: center;
    background: #111; color: #374151; font-size: 32px;
}
.entry-body { padding: 12px; flex: 1; display: flex; flex-direction: column; gap: 4px; }
.entry-name { font-weight: 700; font-size: 15px; }
.entry-time { font-size: 12px; color: #9ca3af; }
.entry-pct  { font-size: 12px; color: #4ade80; }
.match-bar  {
    height: 4px; background: #374151; border-radius: 2px; margin-top: 6px; overflow: hidden;
}
.match-bar-fill {
    height: 100%; border-radius: 2px; background: #22c55e;
    transition: width .6s ease;
}
/* Skeleton loader */
.skeleton { background: linear-gradient(90deg,#1f2937 25%,#374151 50%,#1f2937 75%);
            background-size: 200% 100%; animation: shimmer 1.4s infinite; border-radius: 6px; }
@keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
</style>
@endpush

@section('content')

{{-- Header --}}
<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
    <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
            <i class="fas fa-clock-rotate-left text-blue-400"></i>Riwayat Akses
        </h1>
        <p class="text-sm text-gray-400 mt-1">
            Setiap percobaan buka pintu yang berhasil direkam di sini.
        </p>
    </div>
    <div class="flex gap-2">
        <button id="btn-refresh"
            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded text-sm transition flex items-center gap-2">
            <i class="fas fa-sync-alt" id="refresh-icon"></i>Refresh
        </button>
        <button id="btn-clear"
            class="px-4 py-2 bg-red-700 hover:bg-red-800 rounded text-sm transition flex items-center gap-2">
            <i class="fas fa-trash"></i>Hapus Semua
        </button>
    </div>
</div>

{{-- Stats bar --}}
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6" id="stats-bar">
    <div class="bg-gray-800 rounded-lg p-4 text-center">
        <div class="text-2xl font-bold text-blue-400" id="stat-total">—</div>
        <div class="text-xs text-gray-400 mt-1">Total Akses</div>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 text-center">
        <div class="text-2xl font-bold text-green-400" id="stat-today">—</div>
        <div class="text-xs text-gray-400 mt-1">Hari Ini</div>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 text-center">
        <div class="text-2xl font-bold text-purple-400" id="stat-users">—</div>
        <div class="text-xs text-gray-400 mt-1">Pengguna Unik</div>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 text-center">
        <div class="text-2xl font-bold text-yellow-400" id="stat-avg">—</div>
        <div class="text-xs text-gray-400 mt-1">Rata-rata Match</div>
    </div>
</div>

{{-- Filter --}}
<div class="flex items-center gap-3 mb-5 flex-wrap">
    <input type="text" id="filter-name" placeholder="Cari nama…"
        class="px-3 py-2 rounded bg-gray-700 border border-gray-600 focus:border-blue-500
               focus:outline-none text-white text-sm w-48">
    <select id="filter-period"
        class="px-3 py-2 rounded bg-gray-700 border border-gray-600 focus:border-blue-500
               focus:outline-none text-white text-sm">
        <option value="all">Semua waktu</option>
        <option value="today">Hari ini</option>
        <option value="week">7 hari terakhir</option>
        <option value="month">30 hari terakhir</option>
    </select>
    <span id="result-count" class="text-xs text-gray-500 ml-auto"></span>
</div>

{{-- Grid --}}
<div id="history-grid"
    class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
    {{-- filled by JS --}}
</div>

{{-- Empty state --}}
<div id="empty-state" class="hidden text-center py-20 text-gray-500">
    <i class="fas fa-inbox text-6xl mb-4 block text-gray-700"></i>
    <p class="text-lg">Belum ada riwayat akses</p>
    <p class="text-sm mt-1">Riwayat akan muncul setelah ada akses pintu berhasil.</p>
    <a href="{{ route('home') }}"
       class="inline-block mt-5 px-5 py-2 bg-blue-600 hover:bg-blue-700 rounded text-sm text-white transition">
        <i class="fas fa-camera mr-1"></i>Ke Halaman Kamera
    </a>
</div>

{{-- Lightbox --}}
<div id="lightbox"
    class="fixed inset-0 bg-black/80 z-50 hidden items-center justify-center p-4"
    onclick="closeLightbox()">
    <div class="relative max-w-lg w-full" onclick="event.stopPropagation()">
        <img id="lightbox-img" src="" alt="" class="w-full rounded-lg shadow-2xl">
        <div class="mt-3 text-center">
            <div id="lightbox-name"  class="text-lg font-bold"></div>
            <div id="lightbox-time"  class="text-sm text-gray-400 mt-1"></div>
            <div id="lightbox-match" class="text-sm text-green-400 mt-1"></div>
        </div>
        <button onclick="closeLightbox()"
            class="absolute top-2 right-2 w-8 h-8 bg-black/60 hover:bg-black rounded-full
                   flex items-center justify-center text-white text-sm">
            <i class="fas fa-times"></i>
        </button>
    </div>
</div>

@endsection

@push('scripts')
<script>
const API_URL    = '{{ $backendUrl }}';
let allEntries   = [];
let filtered     = [];

// ── Fetch & render ────────────────────────────────────────────────────────────
async function loadHistory() {
    const icon = document.getElementById('refresh-icon');
    icon.classList.add('fa-spin');

    try {
        const r    = await fetch(`${API_URL}/api/history?limit=200`);
        const data = await r.json();
        allEntries = data.history || [];
    } catch {
        allEntries = [];
        showToast('Gagal memuat data — backend tidak dapat dijangkau.', 'red');
    }

    applyFilter();
    renderStats();
    icon.classList.remove('fa-spin');
}

function renderStats() {
    const today = new Date().toISOString().slice(0, 10);
    const todayCount  = allEntries.filter(e => e.timestamp.startsWith(today)).length;
    const uniqueUsers = new Set(allEntries.map(e => e.name)).size;
    const avgMatch    = allEntries.length
        ? Math.round(allEntries.reduce((s, e) => s + (e.percentage || 0), 0) / allEntries.length)
        : 0;

    document.getElementById('stat-total').textContent = allEntries.length;
    document.getElementById('stat-today').textContent = todayCount;
    document.getElementById('stat-users').textContent = uniqueUsers;
    document.getElementById('stat-avg').textContent   = avgMatch ? avgMatch + '%' : '—';
}

function applyFilter() {
    const name   = document.getElementById('filter-name').value.trim().toLowerCase();
    const period = document.getElementById('filter-period').value;
    const now    = new Date();

    filtered = allEntries.filter(e => {
        if (name && !e.name.toLowerCase().includes(name)) return false;
        if (period !== 'all') {
            const ts = new Date(e.timestamp);
            const diffDays = (now - ts) / 86400000;
            if (period === 'today'  && diffDays >= 1)  return false;
            if (period === 'week'   && diffDays >= 7)  return false;
            if (period === 'month'  && diffDays >= 30) return false;
        }
        return true;
    });

    renderGrid(filtered);
    document.getElementById('result-count').textContent =
        filtered.length + ' entri ditampilkan';
}

function renderGrid(entries) {
    const grid  = document.getElementById('history-grid');
    const empty = document.getElementById('empty-state');
    grid.innerHTML = '';

    if (!entries.length) {
        empty.classList.remove('hidden');
        return;
    }
    empty.classList.add('hidden');

    entries.forEach(e => {
        const card  = document.createElement('div');
        card.className = 'entry-card cursor-pointer';
        card.onclick = () => openLightbox(e);

        const ts     = new Date(e.timestamp);
        const date   = ts.toLocaleDateString('id-ID',
            { day:'2-digit', month:'short', year:'numeric' });
        const time   = ts.toLocaleTimeString('id-ID',
            { hour:'2-digit', minute:'2-digit', second:'2-digit' });
        const pct    = Math.round(e.percentage || 0);
        const imgUrl = e.image ? `${API_URL}/history/${e.image}` : '';
        const barW   = Math.min(100, pct);

        card.innerHTML = `
            ${imgUrl
                ? `<img src="${imgUrl}" alt="${e.name}"
                        loading="lazy"
                        onerror="this.outerHTML='<div class=\\'no-img\\'><i class=\\'fas fa-image-slash\\'></i></div>'">`
                : '<div class="no-img"><i class="fas fa-image"></i></div>'}
            <div class="entry-body">
                <div class="entry-name truncate">${e.name}</div>
                <div class="entry-time">${date}</div>
                <div class="entry-time">${time}</div>
                <div class="entry-pct"><i class="fas fa-check-circle mr-1"></i>${pct}% match</div>
                <div class="match-bar">
                    <div class="match-bar-fill" style="width:${barW}%"></div>
                </div>
            </div>`;
        grid.appendChild(card);
    });
}

// ── Lightbox ──────────────────────────────────────────────────────────────────
function openLightbox(e) {
    const ts     = new Date(e.timestamp);
    const imgUrl = e.image ? `${API_URL}/history/${e.image}` : '';
    const date   = ts.toLocaleDateString('id-ID',
        { weekday:'long', day:'2-digit', month:'long', year:'numeric' });
    const time   = ts.toLocaleTimeString('id-ID',
        { hour:'2-digit', minute:'2-digit', second:'2-digit' });

    document.getElementById('lightbox-img').src   = imgUrl;
    document.getElementById('lightbox-name').textContent  = e.name;
    document.getElementById('lightbox-time').textContent  = `${date} · ${time}`;
    document.getElementById('lightbox-match').textContent = `Kecocokan: ${Math.round(e.percentage)}%`;
    document.getElementById('lightbox').classList.replace('hidden', 'flex');
}
function closeLightbox() {
    document.getElementById('lightbox').classList.replace('flex', 'hidden');
    document.getElementById('lightbox-img').src = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });

// ── Clear history ─────────────────────────────────────────────────────────────
document.getElementById('btn-clear').addEventListener('click', async () => {
    if (!confirm('Hapus seluruh riwayat akses dan gambar? Tindakan ini tidak bisa dibatalkan.')) return;
    try {
        await fetch(`${API_URL}/api/history`, { method: 'DELETE' });
        allEntries = []; filtered = [];
        renderGrid([]);
        renderStats();
        showToast('Riwayat berhasil dihapus.', 'green');
    } catch {
        showToast('Gagal menghapus riwayat.', 'red');
    }
});

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, color = 'green') {
    const t = document.createElement('div');
    t.className = `fixed bottom-6 right-6 z-50 px-5 py-3 rounded-lg text-white text-sm shadow-xl
                   bg-${color}-700 transition-opacity duration-500`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 500); }, 3000);
}

// ── Filters ───────────────────────────────────────────────────────────────────
document.getElementById('filter-name').addEventListener('input',    applyFilter);
document.getElementById('filter-period').addEventListener('change', applyFilter);
document.getElementById('btn-refresh').addEventListener('click',    loadHistory);

// ── INIT ──────────────────────────────────────────────────────────────────────
loadHistory();
</script>
@endpush
