@extends('layouts.app')

@section('title', 'Riwayat Akses — HomeSafe')

@php
    $backendUrl = config('app.backend_url', env('BACKEND_URL', 'http://127.0.0.1:5001'));
@endphp

@push('styles')
<style>
    /* ── Page header ── */
    .page-header {
        background: rgba(30,41,59,0.4);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 16px;
        padding: 20px 24px;
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: 16px;
        margin-bottom: 24px;
    }
    html:not(.dark) .page-header {
        background: rgba(255,255,255,0.55);
        border-color: rgba(0,0,0,0.06);
    }

    /* ── Stat cards ── */
    .stat-card {
        background: rgba(30,41,59,0.45);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 14px;
        padding: 18px 20px;
        transition: all 0.25s ease;
    }
    .stat-card:hover { transform: translateY(-2px); border-color: rgba(255,255,255,0.12); }
    html:not(.dark) .stat-card {
        background: rgba(255,255,255,0.55);
        border-color: rgba(0,0,0,0.06);
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }

    /* ── Filter bar ── */
    .filter-glass {
        background: rgba(30,41,59,0.4);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 12px;
        padding: 12px 16px;
        display: flex; align-items: center; flex-wrap: wrap; gap: 10px;
        margin-bottom: 20px;
    }
    html:not(.dark) .filter-glass {
        background: rgba(255,255,255,0.5);
        border-color: rgba(0,0,0,0.06);
    }
    .filter-input {
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 8px;
        padding: 7px 12px;
        font-size: 0.8125rem;
        color: #f1f5f9;
        outline: none;
        transition: border-color 0.2s;
        font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .filter-input::placeholder { color: #64748b; }
    .filter-input:focus { border-color: rgba(59,130,246,0.5); }
    html:not(.dark) .filter-input {
        background: rgba(0,0,0,0.03);
        border-color: rgba(0,0,0,0.08);
        color: #0f172a;
    }
    html:not(.dark) .filter-input::placeholder { color: #94a3b8; }
    html:not(.dark) .filter-input:focus { border-color: rgba(37,99,235,0.4); }

    /* ── History card ── */
    .entry-card {
        background: rgba(30,41,59,0.45);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 14px;
        overflow: hidden;
        cursor: pointer;
        transition: all 0.25s ease;
        display: flex; flex-direction: column;
    }
    html:not(.dark) .entry-card {
        background: rgba(255,255,255,0.55);
        border-color: rgba(0,0,0,0.07);
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .entry-card:hover {
        border-color: rgba(59,130,246,0.25);
        transform: translateY(-3px);
        box-shadow: 0 12px 32px rgba(59,130,246,0.08);
    }
    .entry-card img, .entry-card .no-img {
        width: 100%; aspect-ratio: 4/3; object-fit: cover; background: #0d1117;
        display: block;
    }
    .no-img {
        display: flex !important; align-items: center; justify-content: center;
        color: rgba(100,116,139,0.4); font-size: 28px;
    }
    .entry-body { padding: 12px; flex: 1; display: flex; flex-direction: column; gap: 3px; }
    .entry-name { font-weight: 600; font-size: 0.8125rem; color: #f1f5f9; }
    html:not(.dark) .entry-name { color: #0f172a; }
    .entry-time { font-size: 0.7rem; color: #64748b; }
    .entry-pct  { font-size: 0.7rem; color: #34d399; font-weight: 500; }
    .match-bar  {
        height: 3px; background: rgba(255,255,255,0.07); border-radius: 99px;
        margin-top: 6px; overflow: hidden;
    }
    html:not(.dark) .match-bar { background: rgba(0,0,0,0.07); }
    .match-bar-fill {
        height: 100%; border-radius: 99px; background: #10b981;
        transition: width 0.6s ease;
    }

    /* ── Action buttons ── */
    .btn-action {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 8px 16px; border-radius: 9px;
        font-size: 0.8rem; font-weight: 500;
        cursor: pointer; border: none;
        transition: all 0.2s ease;
        font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .btn-primary { background: #3b82f6; color: #fff; }
    .btn-primary:hover { background: #2563eb; transform: scale(1.02); }
    .btn-danger {
        background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.2);
        color: #f87171;
    }
    .btn-danger:hover { background: rgba(239,68,68,0.2); transform: scale(1.02); }
    html:not(.dark) .btn-danger {
        background: rgba(220,38,38,0.07); border-color: rgba(220,38,38,0.15);
        color: #dc2626;
    }

    /* ── Lightbox ── */
    #lightbox { backdrop-filter: blur(8px); }
    .lightbox-glass {
        background: rgba(15,23,42,0.85);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 20px;
        overflow: hidden;
        max-width: 480px; width: 100%;
    }
    html:not(.dark) .lightbox-glass {
        background: rgba(255,255,255,0.9);
        border-color: rgba(0,0,0,0.1);
    }

    /* ── Empty state ── */
    .empty-glass {
        background: rgba(30,41,59,0.4);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 20px;
        padding: 64px 32px; text-align: center;
    }
    html:not(.dark) .empty-glass {
        background: rgba(255,255,255,0.5);
        border-color: rgba(0,0,0,0.06);
    }

    /* ── Skeleton ── */
    .skeleton {
        background: rgba(255,255,255,0.05);
        border-radius: 8px;
        animation: shimmer 1.5s infinite;
        background-size: 200% 100%;
    }
    html:not(.dark) .skeleton { background: rgba(0,0,0,0.05); }
    @keyframes shimmer {
        0%   { opacity: 0.5; }
        50%  { opacity: 1;   }
        100% { opacity: 0.5; }
    }
</style>
@endpush

@section('content')

<!-- Page header -->
<div class="page-header">
    <div>
        <h1 class="text-xl font-bold text-primary flex items-center gap-2.5">
            <span class="w-8 h-8 rounded-lg bg-blue-500/15 border border-blue-500/20 flex items-center justify-center text-blue-400">
                <i class="fas fa-clock-rotate-left text-sm"></i>
            </span>
            Riwayat Akses
        </h1>
        <p class="text-secondary text-sm mt-1">Rekaman setiap akses pintu yang berhasil diverifikasi</p>
    </div>
    <div class="flex gap-2">
        <button id="btn-refresh" class="btn-action btn-primary">
            <i class="fas fa-arrows-rotate text-xs" id="refresh-icon"></i>Refresh
        </button>
        <button id="btn-clear" class="btn-action btn-danger">
            <i class="fas fa-trash text-xs"></i>Hapus Semua
        </button>
    </div>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
    <div class="stat-card">
        <div class="text-[11px] text-secondary font-medium uppercase tracking-wide mb-2">Total Akses</div>
        <div class="text-2xl font-bold text-blue-400" id="stat-total">—</div>
    </div>
    <div class="stat-card">
        <div class="text-[11px] text-secondary font-medium uppercase tracking-wide mb-2">Hari Ini</div>
        <div class="text-2xl font-bold text-emerald-400" id="stat-today">—</div>
    </div>
    <div class="stat-card">
        <div class="text-[11px] text-secondary font-medium uppercase tracking-wide mb-2">Pengguna Unik</div>
        <div class="text-2xl font-bold" style="color:#a78bfa;" id="stat-users">—</div>
    </div>
    <div class="stat-card">
        <div class="text-[11px] text-secondary font-medium uppercase tracking-wide mb-2">Rata-rata Match</div>
        <div class="text-2xl font-bold" style="color:#f59e0b;" id="stat-avg">—</div>
    </div>
</div>

<!-- Filter bar -->
<div class="filter-glass">
    <i class="fas fa-magnifying-glass text-secondary text-xs"></i>
    <input type="text" id="filter-name" placeholder="Cari nama…" class="filter-input w-44">
    <select id="filter-period" class="filter-input">
        <option value="all">Semua waktu</option>
        <option value="today">Hari ini</option>
        <option value="week">7 hari terakhir</option>
        <option value="month">30 hari terakhir</option>
    </select>
    <span id="result-count" class="text-secondary text-xs ml-auto font-medium"></span>
</div>

<!-- History grid -->
<div id="history-grid"
    class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
</div>

<!-- Empty state -->
<div id="empty-state" class="hidden empty-glass">
    <div class="w-14 h-14 rounded-xl bg-slate-700/40 flex items-center justify-center mx-auto mb-4">
        <i class="fas fa-inbox text-xl text-slate-500"></i>
    </div>
    <h3 class="text-primary font-semibold mb-1">Belum ada riwayat</h3>
    <p class="text-secondary text-sm mb-5">Riwayat akan muncul setelah ada akses pintu yang berhasil.</p>
    <a href="{{ route('home') }}" class="btn-action btn-primary" style="margin: 0 auto; text-decoration:none;">
        <i class="fas fa-video text-xs"></i>Ke Halaman Monitoring
    </a>
</div>

<!-- Lightbox -->
<div id="lightbox" class="fixed inset-0 bg-black/60 z-50 hidden items-center justify-center p-4"
     onclick="closeLightbox()">
    <div class="lightbox-glass" onclick="event.stopPropagation()">
        <div class="relative">
            <img id="lightbox-img" src="" alt="" class="w-full" style="max-height:320px;object-fit:cover;">
            <button onclick="closeLightbox()"
                class="absolute top-3 right-3 w-8 h-8 rounded-full bg-black/60 hover:bg-black flex items-center justify-center text-white text-xs transition-all">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="p-5">
            <div class="font-bold text-primary text-base" id="lightbox-name"></div>
            <div class="text-secondary text-xs mt-1" id="lightbox-time"></div>
            <div class="text-emerald-400 text-xs mt-1 font-medium" id="lightbox-match"></div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
const API_URL  = '{{ $backendUrl }}';
let allEntries = [], filtered = [];

async function loadHistory() {
    const icon = document.getElementById('refresh-icon');
    icon.classList.add('fa-spin');
    try {
        const r = await fetch(`${API_URL}/api/history?limit=200`);
        allEntries = (await r.json()).history || [];
    } catch { allEntries = []; }
    applyFilter(); renderStats();
    icon.classList.remove('fa-spin');
}

function renderStats() {
    const today = new Date().toISOString().slice(0,10);
    document.getElementById('stat-total').textContent = allEntries.length;
    document.getElementById('stat-today').textContent = allEntries.filter(e=>e.timestamp.startsWith(today)).length;
    document.getElementById('stat-users').textContent = new Set(allEntries.map(e=>e.name)).size;
    const avg = allEntries.length
        ? Math.round(allEntries.reduce((s,e)=>s+(e.percentage||0),0)/allEntries.length)
        : 0;
    document.getElementById('stat-avg').textContent = avg ? avg+'%' : '—';
}

function applyFilter() {
    const name = document.getElementById('filter-name').value.trim().toLowerCase();
    const period = document.getElementById('filter-period').value;
    const now = new Date();
    filtered = allEntries.filter(e => {
        if (name && !e.name.toLowerCase().includes(name)) return false;
        if (period !== 'all') {
            const d = (now - new Date(e.timestamp)) / 86400000;
            if (period==='today' && d>=1) return false;
            if (period==='week'  && d>=7) return false;
            if (period==='month' && d>=30) return false;
        }
        return true;
    });
    renderGrid(filtered);
    document.getElementById('result-count').textContent = filtered.length + ' entri';
}

function renderGrid(entries) {
    const grid = document.getElementById('history-grid');
    const empty = document.getElementById('empty-state');
    grid.innerHTML = '';
    if (!entries.length) { empty.classList.remove('hidden'); return; }
    empty.classList.add('hidden');

    entries.forEach(e => {
        const card = document.createElement('div');
        card.className = 'entry-card';
        card.onclick = () => openLightbox(e);
        const ts = new Date(e.timestamp);
        const pct = Math.round(e.percentage||0);
        const imgUrl = e.image ? `${API_URL}/history/${e.image}` : '';
        card.innerHTML = `
            ${imgUrl
                ? `<img src="${imgUrl}" alt="${e.name}" loading="lazy"
                        onerror="this.outerHTML='<div class=\\'no-img\\'><i class=\\'fas fa-image text-slate-600\\'></i></div>'">`
                : '<div class="no-img"><i class="fas fa-image text-slate-600"></i></div>'}
            <div class="entry-body">
                <div class="entry-name truncate">${e.name}</div>
                <div class="entry-time">${ts.toLocaleDateString('id-ID',{day:'2-digit',month:'short'})}</div>
                <div class="entry-time">${ts.toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit',second:'2-digit'})}</div>
                <div class="entry-pct">${pct}% match</div>
                <div class="match-bar"><div class="match-bar-fill" style="width:${Math.min(100,pct)}%"></div></div>
            </div>`;
        grid.appendChild(card);
    });
}

function openLightbox(e) {
    const ts = new Date(e.timestamp);
    document.getElementById('lightbox-img').src = e.image ? `${API_URL}/history/${e.image}` : '';
    document.getElementById('lightbox-name').textContent = e.name;
    document.getElementById('lightbox-time').textContent =
        ts.toLocaleDateString('id-ID',{weekday:'long',day:'2-digit',month:'long',year:'numeric'}) + ' · ' +
        ts.toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    document.getElementById('lightbox-match').textContent = 'Kecocokan: ' + Math.round(e.percentage) + '%';
    document.getElementById('lightbox').classList.replace('hidden','flex');
}
function closeLightbox() {
    document.getElementById('lightbox').classList.replace('flex','hidden');
    document.getElementById('lightbox-img').src = '';
}
document.addEventListener('keydown', e => { if(e.key==='Escape') closeLightbox(); });

document.getElementById('btn-clear').addEventListener('click', async () => {
    if (!confirm('Hapus seluruh riwayat akses?')) return;
    await fetch(`${API_URL}/api/history`, { method:'DELETE' });
    allEntries=[]; filtered=[]; renderGrid([]); renderStats();
});

document.getElementById('filter-name').addEventListener('input', applyFilter);
document.getElementById('filter-period').addEventListener('change', applyFilter);
document.getElementById('btn-refresh').addEventListener('click', loadHistory);

loadHistory();
</script>
@endpush
