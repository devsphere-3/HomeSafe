@extends('layouts.app')

@section('title', 'Smart Lock - Face Recognition')

@php
    $backendUrl = config('app.backend_url', 'http://127.0.0.1:5001');
    $wsUrl = str_replace(['https://', 'http://'], ['wss://', 'ws://'], $backendUrl);
@endphp

@push('styles')
<style>
/* ── Page layout ── */
.recog-wrap { display: flex; flex-direction: column; gap: 1rem; }

/* ── Connection pill bar ── */
.conn-bar {
    display: flex; align-items: center; gap: 0.75rem;
    padding: 0.45rem 1rem;
    background: rgba(8, 18, 36, 0.72);
    backdrop-filter: blur(20px) saturate(1.3);
    -webkit-backdrop-filter: blur(20px) saturate(1.3);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 999px;
    width: fit-content;
    font-size: 0.72rem;
    color: #94a3b8;
    position: relative;
    z-index: 1;
    box-shadow: 0 2px 12px rgba(0,0,0,0.25), inset 0 1px 0 rgba(255,255,255,0.05);
}
html:not(.dark) .conn-bar {
    background: rgba(210, 222, 240, 0.75);
    border-color: rgba(148,163,184,0.25);
    color: #4a5568;
    box-shadow: 0 2px 8px rgba(15,23,42,0.10), inset 0 1px 0 rgba(255,255,255,0.5);
}
.conn-bar .sep { width: 1px; height: 12px; background: rgba(148,163,184,0.2); }
.conn-bar .backend-label { margin-left: auto; color: #475569; font-size: 0.68rem; }
html:not(.dark) .conn-bar .backend-label { color: #94a3b8; }

/* ── Glass card ── */
.g-card {
    position: relative;
    background: rgba(12, 24, 45, 0.68);
    backdrop-filter: blur(24px) saturate(1.4);
    -webkit-backdrop-filter: blur(24px) saturate(1.4);
    border: 1px solid rgba(255,255,255,0.09);
    border-radius: 16px;
    padding: 1rem;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0,0,0,0.28), inset 0 1px 0 rgba(255,255,255,0.06);
}
html:not(.dark) .g-card {
    background: rgba(210, 222, 240, 0.68);
    border-color: rgba(148,163,184,0.24);
    box-shadow: 0 4px 20px rgba(15,23,42,0.10), inset 0 1px 0 rgba(255,255,255,0.55);
}

/* ── Card header ── */
.g-card-header {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.875rem;
}
.g-card-title {
    font-weight: 600; font-size: 0.875rem;
    display: flex; align-items: center; gap: 0.4rem;
    color: #f1f5f9;
}
html:not(.dark) .g-card-title { color: #0f172a; }

/* ── Status badge ── */
.status-badge {
    display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 0.25rem 0.65rem;
    border-radius: 999px;
    font-size: 0.7rem; font-weight: 500;
    border: 1px solid transparent;
}
.status-badge.yellow { background: rgba(234,179,8,0.15); border-color: rgba(234,179,8,0.3); color: #fde047; }
.status-badge.green  { background: rgba(16,185,129,0.15); border-color: rgba(16,185,129,0.3); color: #6ee7b7; }
.status-badge.red    { background: rgba(239,68,68,0.15);  border-color: rgba(239,68,68,0.3);  color: #fca5a5; }
.status-badge.blue   { background: rgba(59,130,246,0.15); border-color: rgba(59,130,246,0.3); color: #93c5fd; }
.status-badge.orange { background: rgba(249,115,22,0.15); border-color: rgba(249,115,22,0.3); color: #fdba74; }
.status-badge.gray   { background: rgba(100,116,139,0.15);border-color: rgba(100,116,139,0.25);color: #94a3b8; }

/* ── Camera feed ── */
.cam-feed {
    position: relative;
    background: rgba(15,23,42,0.7);
    border-radius: 10px;
    overflow: hidden;
    aspect-ratio: 4/3;
    min-height: 200px;
    border: 1px solid rgba(255,255,255,0.06);
}
.cam-feed canvas {
    position: absolute; inset: 0; width: 100%; height: 100%;
}

/* ── Overlays on camera feed ── */
.badge-alert { position: absolute; top: 8px; right: 8px; z-index: 10; }
.lock-bar    { position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%); z-index: 10; white-space: nowrap; }

#motion-flash {
    position: absolute; inset: 0; pointer-events: none; z-index: 15;
    border: 4px solid transparent; transition: border-color 0.1s;
    border-radius: 10px;
}
#motion-flash.active { border-color: #ef4444; box-shadow: inset 0 0 40px rgba(239,68,68,0.4); }

#unlock-overlay, #camera-off-overlay {
    position: absolute; inset: 0; display: flex; flex-direction: column;
    align-items: center; justify-content: center; z-index: 20;
    pointer-events: none; opacity: 0; transition: opacity 0.4s;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
}
#unlock-overlay.show, #camera-off-overlay.show { opacity: 1; pointer-events: auto; }

.check-icon { font-size: 3.5rem; color: #10b981; }

/* ── Lock status pill ── */
.lock-pill {
    display: inline-flex; align-items: center; gap: 0.5rem;
    padding: 0.4rem 1rem;
    border-radius: 999px;
    font-size: 0.78rem; font-weight: 600;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.1);
    transition: background 0.3s;
}
.lock-pill.locked   { background: rgba(15,23,42,0.82); color: #f1f5f9; }
.lock-pill.unlocked { background: rgba(16,185,129,0.22); border-color: rgba(16,185,129,0.35); color: #6ee7b7; }

/* ── Stats row ── */
.stats-row {
    display: flex; flex-wrap: wrap; gap: 0.4rem;
    margin-top: 0.75rem;
    font-size: 0.72rem;
}
.stat-chip {
    display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 0.2rem 0.6rem;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 999px;
    color: #94a3b8;
}
html:not(.dark) .stat-chip {
    background: rgba(0,0,0,0.04);
    border-color: rgba(0,0,0,0.08);
    color: #64748b;
}
.stat-chip .val { font-weight: 600; color: #e2e8f0; }
html:not(.dark) .stat-chip .val { color: #0f172a; }
.fps-high { color: #10b981; } .fps-mid { color: #f59e0b; } .fps-low { color: #ef4444; }

/* ── Buttons ── */
.btn-sm {
    display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 0.35rem 0.75rem;
    border-radius: 8px; font-size: 0.75rem; font-weight: 500;
    cursor: pointer; border: 1px solid transparent;
    transition: opacity 0.15s, transform 0.1s;
}
.btn-sm:active { transform: scale(0.97); }
.btn-blue  { background: #3b82f6; color: #fff; border-color: rgba(59,130,246,0.5); }
.btn-blue:hover  { background: #2563eb; }
.btn-green { background: #10b981; color: #fff; border-color: rgba(16,185,129,0.5); }
.btn-green:hover { background: #059669; }
.btn-red   { background: #ef4444; color: #fff; border-color: rgba(239,68,68,0.5); }
.btn-red:hover   { background: #dc2626; }
.btn-gray  {
    background: rgba(100,116,139,0.18);
    border-color: rgba(100,116,139,0.25);
    color: #94a3b8;
}
.btn-gray:hover { background: rgba(100,116,139,0.28); color: #f1f5f9; }

/* ── Camera select ── */
.cam-select {
    background: rgba(30,41,59,0.6);
    border: 1px solid rgba(255,255,255,0.1);
    color: #e2e8f0;
    padding: 0.3rem 0.65rem;
    border-radius: 8px;
    font-size: 0.72rem;
    min-width: 190px; max-width: 300px;
    font-family: inherit;
}
html:not(.dark) .cam-select {
    background: rgba(255,255,255,0.6);
    border-color: rgba(0,0,0,0.1);
    color: #0f172a;
}
.cam-select:focus { outline: none; border-color: rgba(59,130,246,0.5); }
.cam-select:disabled { opacity: 0.45; cursor: not-allowed; }
option:disabled { color: #6b7280; }

/* ── Log panel ── */
.log-inner {
    max-height: 260px; overflow-y: auto;
    display: flex; flex-direction: column; gap: 0.35rem;
    padding-right: 2px;
}

/* ── History / motion cards ── */
.history-card, .motion-card {
    display: flex; align-items: center; gap: 0.6rem;
    padding: 0.5rem 0.65rem;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 10px;
    border-left: 3px solid rgba(100,116,139,0.4);
}
html:not(.dark) .history-card, html:not(.dark) .motion-card {
    background: rgba(0,0,0,0.03);
    border-color: rgba(0,0,0,0.08);
    border-left-color: rgba(100,116,139,0.3);
}
.history-card img { width: 46px; height: 34px; object-fit: cover; border-radius: 6px; flex-shrink: 0; }
.motion-card.alert {
    border-left-color: #ef4444;
    background: rgba(239,68,68,0.08);
}

/* ── Main two-column grid ── */
.grid-main { display: grid; grid-template-columns: 1fr 300px; gap: 1rem; }
@media (max-width: 1024px) { .grid-main { grid-template-columns: 1fr; } }

/* ── Divider ── */
.cam-divider { width: 1px; background: rgba(148,163,184,0.15); align-self: stretch; display: none; }
@media (min-width: 640px) { .cam-divider { display: block; } }
</style>
@endpush

@section('content')
<div class="recog-wrap">

{{-- 1. Connection status bar --}}
<div class="conn-bar">
    <span class="flex items-center gap-1.5">
        <i id="conn-dot" class="fas fa-circle text-[7px] text-red-400"></i>
        <span id="conn-text" class="text-red-400">Offline</span>
        <span class="text-slate-500">— Kamera Pintu</span>
    </span>
    <div class="sep"></div>
    <span class="flex items-center gap-1.5">
        <i id="yard-conn-dot" class="fas fa-circle text-[7px] text-red-400"></i>
        <span id="yard-conn-text" class="text-red-400">Offline</span>
        <span class="text-slate-500">— CCTV</span>
    </span>
    <div class="sep"></div>
    <span class="backend-label">Backend: {{ $backendUrl }}</span>
</div>

{{-- 2. Camera selector panel --}}
<div class="g-card">
    <div class="flex flex-wrap items-center gap-3 mb-2.5">
        {{-- Door --}}
        <div class="flex items-center gap-2 flex-wrap">
            <i class="fas fa-door-open text-blue-400 text-sm flex-shrink-0"></i>
            <label class="text-xs text-slate-400 whitespace-nowrap">Pintu (Face Recognition):</label>
            <select id="door-cam-select" class="cam-select" disabled>
                <option value="">Memuat…</option>
            </select>
            <button id="btn-apply-door" class="btn-sm btn-blue">
                <i class="fas fa-check"></i>Terapkan
            </button>
        </div>
        <div class="cam-divider"></div>
        {{-- Yard --}}
        <div class="flex items-center gap-2 flex-wrap">
            <i class="fas fa-video text-emerald-400 text-sm flex-shrink-0"></i>
            <label class="text-xs text-slate-400 whitespace-nowrap">CCTV (Motion):</label>
            <select id="yard-cam-select" class="cam-select" disabled>
                <option value="">Memuat…</option>
            </select>
            <button id="btn-apply-yard" class="btn-sm btn-blue">
                <i class="fas fa-check"></i>Terapkan
            </button>
        </div>
        <button id="btn-refresh-cameras" class="btn-sm btn-gray ml-auto">
            <i class="fas fa-sync-alt"></i>Refresh
        </button>
    </div>
    <p id="cam-selector-note" class="text-[0.7rem] text-slate-500">
        <i class="fas fa-spinner fa-spin mr-1"></i>Mendeteksi kamera…
    </p>
</div>

{{-- 3. Two-column main grid --}}
<div class="grid-main">

    {{-- Left column: dual camera feeds --}}
    <div class="flex flex-col gap-4">

        {{-- 4. Door feed — face recognition --}}
        <div class="g-card">
            <div class="g-card-header">
                <span class="g-card-title">
                    <i class="fas fa-door-open text-blue-400"></i>
                    <span id="door-cam-title">Kamera Pintu — Face Recognition</span>
                </span>
                <span id="recognition-status" class="status-badge yellow">
                    <i class="fas fa-circle-notch fa-spin"></i>Menghubungkan…
                </span>
            </div>

            {{-- Camera feed with overlays --}}
            <div class="cam-feed">
                <canvas id="overlay-canvas"></canvas>

                {{-- ACCESS GRANTED overlay --}}
                <div id="unlock-overlay">
                    <div class="check-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="text-lg font-bold text-emerald-400 mt-2 tracking-wide">ACCESS GRANTED</div>
                    <div id="unlock-name-display" class="text-emerald-300 text-sm mt-1 font-medium"></div>
                    <div id="unlock-pct-display" class="text-emerald-400/70 text-xs mt-1"></div>
                </div>

                {{-- Camera-off / countdown overlay --}}
                <div id="camera-off-overlay">
                    <i class="fas fa-check-circle text-3xl text-emerald-400 mb-3"></i>
                    <p class="text-emerald-300 text-sm font-semibold mb-1">Akses Diberikan</p>
                    <p class="text-slate-400 text-xs mb-3">
                        Memulai ulang dalam <span id="restart-countdown">5</span> detik…
                    </p>
                    <button id="btn-cam-off-restart" class="btn-sm btn-blue pointer-events-auto">
                        <i class="fas fa-redo"></i>Mulai Sekarang
                    </button>
                </div>

                {{-- Lock status bar --}}
                <div class="lock-bar">
                    <div id="lock-status" class="lock-pill locked">
                        <i id="lock-icon" class="fas fa-lock text-red-400"></i>
                        <span id="lock-text" class="text-xs font-semibold tracking-wider">LOCKED</span>
                        <span id="user-info" class="hidden text-xs text-emerald-300 font-normal"></span>
                    </div>
                </div>
            </div>

            {{-- Stats row --}}
            <div class="stats-row">
                <span class="stat-chip">Wajah: <span class="val" id="face-count">0</span></span>
                <span class="stat-chip">Proses: <span class="val" id="process-time">0</span> ms</span>
                <span class="stat-chip">FPS: <span class="val fps-high" id="fps-counter">0</span></span>
                <span class="stat-chip hidden" id="user-indicator-wrapper" style="border-color:rgba(16,185,129,0.3);">
                    <i class="fas fa-user-check text-emerald-400"></i>
                    <span class="val" id="user-indicator"></span>
                </span>
            </div>
            <button id="btn-restart-scan" class="btn-sm btn-gray mt-2 hidden">
                <i class="fas fa-redo"></i>Scan Ulang
            </button>
        </div>

        {{-- 5. CCTV feed — motion detection --}}
        <div class="g-card">
            <div class="g-card-header">
                <span class="g-card-title">
                    <i class="fas fa-video text-emerald-400"></i>
                    <span id="yard-cam-title">CCTV — Deteksi Gerakan</span>
                </span>
                <div class="flex items-center gap-2">
                    <span id="motion-status-chip" class="status-badge gray">
                        <i class="fas fa-circle-notch fa-spin"></i>
                        <span>Menghubungkan…</span>
                    </span>
                    <span id="yard-status" class="status-badge yellow">
                        <i class="fas fa-circle-notch fa-spin"></i>Menghubungkan…
                    </span>
                </div>
            </div>

            <div class="cam-feed">
                <canvas id="yard-canvas"></canvas>
                <div id="motion-flash"></div>
                <span id="motion-alert-badge" class="badge-alert hidden">
                    <span class="status-badge red" style="animation: pulse 1s infinite;">
                        <i class="fas fa-walking"></i>Ada Tamu!
                    </span>
                </span>
            </div>

            <div class="stats-row">
                <span class="stat-chip">FPS: <span class="val fps-high" id="yard-fps">0</span></span>
                <span class="stat-chip">Area gerak: <span class="val" id="motion-ratio">0%</span></span>
                <span class="stat-chip hidden" id="motion-count-chip">
                    <i class="fas fa-bell text-red-400"></i>
                    <span class="val text-red-400" id="motion-count">0</span> alert
                </span>
            </div>
        </div>

    </div>{{-- end left column --}}

    {{-- Right column: logs panel --}}
    <div class="flex flex-col gap-4">

        {{-- Access history log --}}
        <div class="g-card flex flex-col gap-0" style="flex: 1;">
            <div class="g-card-header" style="margin-bottom: 0.6rem;">
                <span class="g-card-title">
                    <i class="fas fa-clock-rotate-left text-emerald-400"></i>
                    Riwayat Akses
                </span>
            </div>
            <div class="log-inner" id="history-list"></div>
            <p id="history-empty" class="text-[0.7rem] text-slate-500 text-center py-4">
                Belum ada akses tercatat
            </p>
        </div>

        {{-- Motion log --}}
        <div class="g-card flex flex-col gap-0" style="flex: 1;">
            <div class="g-card-header" style="margin-bottom: 0.6rem;">
                <span class="g-card-title">
                    <i class="fas fa-bell text-red-400"></i>
                    Log Deteksi Gerakan
                </span>
                <button id="btn-clear-motion" class="btn-sm btn-gray" style="padding: 0.2rem 0.55rem; font-size: 0.68rem;">
                    <i class="fas fa-trash"></i>Hapus
                </button>
            </div>
            <div class="log-inner" id="motion-log-list"></div>
            <p id="motion-log-empty" class="text-[0.7rem] text-slate-500 text-center py-4">
                Belum ada gerakan terdeteksi
            </p>
        </div>

    </div>{{-- end right column --}}

</div>{{-- end grid-main --}}
</div>{{-- end recog-wrap --}}
@endsection

@push('scripts')
<script>
// ── CONFIG ────────────────────────────────────────────────────────────────────
const API_URL    = '{{ $backendUrl }}';
const WS_URL     = '{{ $wsUrl }}/ws';
const WS_MOT_URL = '{{ $wsUrl }}/ws/motion';
const MAX_RC     = 15000;

// ── DOM — door ────────────────────────────────────────────────────────────────
const canvas       = document.getElementById('overlay-canvas');
const ctx          = canvas.getContext('2d', { alpha: false });
const elStatus     = document.getElementById('recognition-status');
const elFaceCount  = document.getElementById('face-count');
const elProcTime   = document.getElementById('process-time');
const elFpsCtr     = document.getElementById('fps-counter');
const elIndWrap    = document.getElementById('user-indicator-wrapper');
const elInd        = document.getElementById('user-indicator');
const elLockIcon   = document.getElementById('lock-icon');
const elLockText   = document.getElementById('lock-text');
const elUserInfo   = document.getElementById('user-info');
const elLockStatus = document.getElementById('lock-status');
const elUnlockOvr  = document.getElementById('unlock-overlay');
const elUnlockName = document.getElementById('unlock-name-display');
const elUnlockPct  = document.getElementById('unlock-pct-display');
const elCamOff     = document.getElementById('camera-off-overlay');
const elBtnRestart = document.getElementById('btn-restart-scan');
const elBtnCamOff  = document.getElementById('btn-cam-off-restart');
const elHistList   = document.getElementById('history-list');
const elHistEmpty  = document.getElementById('history-empty');
const elConnDot    = document.getElementById('conn-dot');
const elConnText   = document.getElementById('conn-text');

// ── DOM — yard ────────────────────────────────────────────────────────────────
const yardCanvas      = document.getElementById('yard-canvas');
const yardCtx         = yardCanvas.getContext('2d', { alpha: false });
const elYardStatus    = document.getElementById('yard-status');
const elMotionBadge   = document.getElementById('motion-alert-badge');
const elMotionChip    = document.getElementById('motion-status-chip');
const elMotionFlash   = document.getElementById('motion-flash');
const elYardFps       = document.getElementById('yard-fps');
const elMotionCount   = document.getElementById('motion-count');      // ✅ ID sekarang ada
const elMotionCountChip = document.getElementById('motion-count-chip');
const elMotionRatio   = document.getElementById('motion-ratio');
const elYardConnDot   = document.getElementById('yard-conn-dot');
const elYardConnText  = document.getElementById('yard-conn-text');
const elMotionLogList = document.getElementById('motion-log-list');
const elMotionEmpty   = document.getElementById('motion-log-empty');

// ── DOM — camera titles ───────────────────────────────────────────────────────
const elDoorCamTitle = document.getElementById('door-cam-title');
const elYardCamTitle = document.getElementById('yard-cam-title');

// ── DOM — selector ────────────────────────────────────────────────────────────
const elDoorSel = document.getElementById('door-cam-select');
const elYardSel = document.getElementById('yard-cam-select');
const elNote    = document.getElementById('cam-selector-note');

// ── STATE ─────────────────────────────────────────────────────────────────────
let ws = null, wsMotion = null;
let rcTimer = null, rcMotionTimer = null;
let rcAttempts = 0, rcMotionAttempts = 0;
let lastResult = null;
let motionAlertCount = 0;   // counter total alert gerakan di sesi ini

// Cooldown: cegah notifikasi "ada tamu" berulang dalam X detik
const MOTION_COOLDOWN_MS = 8000;  // 8 detik jeda antar notifikasi
let lastMotionAlertAt    = 0;

// Auto-reload: restart session if no face detected for 3 seconds
let noFaceTimer = null;
const NO_FACE_TIMEOUT = 3000; // ms

function resetNoFaceTimer() {
    clearTimeout(noFaceTimer);
    noFaceTimer = null;
}

function startNoFaceTimer() {
    if (noFaceTimer) return;
    noFaceTimer = setTimeout(() => {
        noFaceTimer = null;
        restartSession();
    }, NO_FACE_TIMEOUT);
}
</script>

<script>
// ── FPS helper ────────────────────────────────────────────────────────────────
function makeFps() {
    const b = new Float64Array(60); let i = 0, full = false;
    return () => {
        const now = performance.now(); b[i] = now;
        i = (i+1)%60; if (i===0) full=true;
        const n = full?60:i; if (n<2) return 0;
        return Math.round((n-1)/((now-b[full?i:0])/1000));
    };
}
const tickDoor = makeFps(), tickYard = makeFps();

function b64Blob(b64) {
    const bin=atob(b64), a=new Uint8Array(bin.length);
    for(let i=0;i<bin.length;i++) a[i]=bin.charCodeAt(i);
    return new Blob([a],{type:'image/jpeg'});
}

// ═════════════════════════════════════════════════════════════════════════════
// CAMERA SELECTOR
// ═════════════════════════════════════════════════════════════════════════════
async function loadCameras(reprobe = false) {
    [elDoorSel, elYardSel].forEach(s => { s.innerHTML = '<option value="">Memuat…</option>'; s.disabled = true; });
    elNote.innerHTML = '<i class="fas fa-spinner fa-spin text-blue-400 mr-1"></i>Mendeteksi kamera…';

    try {
        const endpoint = reprobe ? `${API_URL}/api/cameras/probe` : `${API_URL}/api/cameras`;
        const method   = reprobe ? 'POST' : 'GET';
        const d = await (await fetch(endpoint, { method })).json();
        const cams = d.cameras || [];

        if (!cams.length) {
            [elDoorSel, elYardSel].forEach(s =>
                s.innerHTML = '<option value="">Tidak ada kamera ditemukan</option>');
            elNote.innerHTML =
                '<i class="fas fa-exclamation-triangle text-red-400 mr-1"></i>' +
                'Tidak ada kamera terdeteksi. Periksa sambungan USB ke Raspberry Pi.';
            return;
        }

        const buildOptions = (currentId) => {
            let html = '';
            cams.forEach(c => {
                const avail = c.available === true;
                const icon  = avail ? '✅' : '⛔';
                const label = avail
                    ? `${c.node} — ${c.name} (${c.resolution})`
                    : `${c.node} — ${c.name} [metadata only — tidak bisa capture]`;
                const sel = c.id === currentId ? 'selected' : '';
                const dis = avail ? '' : 'disabled';
                html += `<option value="${c.id}" ${dis} ${sel}>${icon} ${label}</option>`;
            });
            return html;
        };

        elDoorSel.innerHTML = buildOptions(d.door_cam_id);
        elYardSel.innerHTML = buildOptions(d.yard_cam_id);
        elDoorSel.disabled  = false;
        elYardSel.disabled  = false;

        // Update judul card dengan nama kamera aktual dari backend
        const doorCam = cams.find(c => c.id === d.door_cam_id);
        const yardCam = cams.find(c => c.id === d.yard_cam_id);
        if (doorCam && elDoorCamTitle) {
            elDoorCamTitle.textContent = `${doorCam.name} (${doorCam.node}) — Face Recognition`;
        }
        if (yardCam && elYardCamTitle) {
            elYardCamTitle.textContent = `${yardCam.name} (${yardCam.node}) — Deteksi Gerakan`;
        }

        // Update conn-bar labels
        const doorConnLabel = document.querySelector('#conn-text + span');
        const yardConnLabel = document.querySelector('#yard-conn-text + span');
        if (doorCam && doorConnLabel) doorConnLabel.textContent = `— ${doorCam.node}`;
        if (yardCam && yardConnLabel) yardConnLabel.textContent = `— ${yardCam.node}`;

        const availCount = cams.filter(c => c.available).length;
        elNote.innerHTML =
            `<i class="fas fa-info-circle text-blue-400 mr-1"></i>` +
            `${cams.length} node terdeteksi — ` +
            `<span class="text-green-400 font-semibold">${availCount} dapat digunakan (✅)</span>, ` +
            `sisanya metadata only (⛔). ` +
            `Pintu: <code class="text-blue-300">/dev/video${d.door_cam_id}</code> · ` +
            `CCTV: <code class="text-green-300">/dev/video${d.yard_cam_id}</code>`;

    } catch(e) {
        [elDoorSel, elYardSel].forEach(s =>
            s.innerHTML = '<option value="">Backend tidak terjangkau</option>');
        elNote.innerHTML =
            `<i class="fas fa-times-circle text-red-400 mr-1"></i>Gagal terhubung: ${e.message}`;
    }
}
</script>

<script>
async function applyCamera(role, selectEl, btnEl) {
    const id = +selectEl.value;
    if (isNaN(id) || selectEl.value === '') { alert('Pilih kamera terlebih dahulu.'); return; }

    const selectedOpt = selectEl.options[selectEl.selectedIndex];
    if (selectedOpt && selectedOpt.disabled) {
        alert('Kamera ini (metadata only) tidak dapat digunakan.\nPilih node yang ditandai ✅.');
        return;
    }

    btnEl.disabled = true;
    btnEl.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Menerapkan…';

    try {
        const res  = await fetch(`${API_URL}/api/cameras/${role}/${id}`, { method: 'POST' });
        const data = await res.json();

        if (!res.ok) {
            const msg = data.detail || data.message || `HTTP ${res.status}`;
            btnEl.innerHTML = '<i class="fas fa-times mr-1"></i>Gagal';
            btnEl.className = btnEl.className.replace('btn-blue','btn-red');
            elNote.innerHTML = `<i class="fas fa-times-circle text-red-400 mr-1"></i>Gagal: ${msg}`;
            setTimeout(() => {
                btnEl.innerHTML = '<i class="fas fa-check mr-1"></i>Terapkan';
                btnEl.className = btnEl.className.replace('btn-red','btn-blue');
                btnEl.disabled  = false;
            }, 3000);
            return;
        }

        if (data.alive) {
            btnEl.innerHTML = '<i class="fas fa-check mr-1"></i>Diterapkan!';
            btnEl.className = btnEl.className.replace('btn-blue','btn-green');
            elNote.innerHTML = `<i class="fas fa-check-circle text-green-400 mr-1"></i>${data.message}`;
        } else {
            btnEl.innerHTML = '<i class="fas fa-clock mr-1"></i>Menunggu frame…';
            elNote.innerHTML =
                `<i class="fas fa-spinner fa-spin text-yellow-400 mr-1"></i>` +
                `/dev/video${id} sedang dibuka, feed akan muncul otomatis…`;
        }

        setTimeout(() => loadCameras(false), 1200);
        setTimeout(() => {
            btnEl.innerHTML = '<i class="fas fa-check mr-1"></i>Terapkan';
            btnEl.className = btnEl.className.replace('btn-green','btn-blue').replace('btn-red','btn-blue');
            btnEl.disabled  = false;
        }, 3000);

    } catch(e) {
        btnEl.innerHTML = '<i class="fas fa-times mr-1"></i>Error';
        btnEl.className = btnEl.className.replace('btn-blue','btn-red');
        btnEl.disabled  = false;
        elNote.innerHTML = `<i class="fas fa-times-circle text-red-400 mr-1"></i>Error: ${e.message}`;
    }
}

document.getElementById('btn-apply-door').addEventListener('click', () =>
    applyCamera('door', elDoorSel, document.getElementById('btn-apply-door')));
document.getElementById('btn-apply-yard').addEventListener('click', () =>
    applyCamera('yard', elYardSel, document.getElementById('btn-apply-yard')));
document.getElementById('btn-refresh-cameras').addEventListener('click', () => loadCameras(true));
</script>

<script>
// ═════════════════════════════════════════════════════════════════════════════
// DOOR — face recognition
// ═════════════════════════════════════════════════════════════════════════════
function displayFrame(b64) {
    createImageBitmap(b64Blob(b64)).then(bmp => {
        if (canvas.width!==bmp.width||canvas.height!==bmp.height){
            canvas.width=bmp.width; canvas.height=bmp.height;
        }
        ctx.drawImage(bmp,0,0); bmp.close();
        const fps=tickDoor(); elFpsCtr.textContent=fps;
        elFpsCtr.className='val '+(fps<5?'fps-low':fps<15?'fps-mid':'fps-high');
        if (lastResult) drawBbox(lastResult);
    });
}

function drawBbox(data) {
    if (!data.bbox||!data.face_detected) return;
    const {xmin:x=0,ymin:y=0,width:w=0,height:h=0}=data.bbox;
    const color=data.matched?'#22c55e':'#ef4444';
    ctx.strokeStyle=color; ctx.lineWidth=3; ctx.shadowColor=color; ctx.shadowBlur=14;
    ctx.strokeRect(x,y,w,h); ctx.shadowBlur=0;
    const label=data.matched?`${data.name}  ${Math.round(data.percentage)}%`:'Unknown';
    ctx.font='bold 13px system-ui';
    const tw=ctx.measureText(label).width;
    ctx.fillStyle=data.matched?'rgba(0,60,20,.75)':'rgba(60,0,0,.75)';
    ctx.fillRect(x,y-24,tw+14,22); ctx.fillStyle='#fff'; ctx.fillText(label,x+7,y-7);
}

function updateRecogUI(data) {
    lastResult=data;
    elFaceCount.textContent=data.face_count||0;
    elProcTime.textContent=data.process_time_ms||0;
    drawBbox(data);
    if (data.face_detected&&data.matched){
        resetNoFaceTimer();
        elIndWrap.classList.remove('hidden'); elIndWrap.style.display='inline-flex';
        elInd.textContent=`${data.name} (${Math.round(data.percentage)}%)`;
    } else elIndWrap.classList.add('hidden');
    data.unlocked?setLock(true,data.name,data.percentage):setLock(false);
    const qmap={too_small:'Terlalu jauh',too_close:'Terlalu dekat',multiple_faces:'Beberapa wajah'};
    if (data.quality_issue){
        // Quality issue counts as "face present" — reset timer
        resetNoFaceTimer();
        elStatus.className='status-badge orange';
        elStatus.innerHTML=`<i class="fas fa-exclamation-triangle"></i>${qmap[data.quality_issue]||data.quality_issue}`;
    } else if (data.face_detected){
        // Face detected — reset timer
        resetNoFaceTimer();
        elStatus.className='status-badge blue';
        elStatus.innerHTML='<i class="fas fa-face-smile"></i>Wajah terdeteksi';
    } else {
        // No face — start countdown to reload
        startNoFaceTimer();
        elStatus.className='status-badge yellow';
        elStatus.innerHTML='<i class="fas fa-circle-notch fa-spin"></i>Scanning…';
        lastResult=null; elIndWrap.classList.add('hidden');
    }
}

function setLock(ok,name='',pct=0){
    if (ok){
        elLockIcon.className='fas fa-lock-open text-emerald-400';
        elLockText.textContent='UNLOCKED';
        elLockStatus.className='lock-pill unlocked';
        elUserInfo.textContent=`${name}  ${Math.round(pct)}%`;
        elUserInfo.className='text-xs text-emerald-300'; elUserInfo.classList.remove('hidden');
    } else {
        elLockIcon.className='fas fa-lock text-red-400';
        elLockText.textContent='LOCKED';
        elLockStatus.className='lock-pill locked';
        elUserInfo.classList.add('hidden');
    }
}
</script>

<script>
const AUTO_RESTART_DELAY = 5;   // detik sebelum auto-restart setelah unlock

let autoRestartTimer   = null;
let countdownInterval  = null;
const elCountdown      = () => document.getElementById('restart-countdown');

function startAutoRestart() {
    // Clear any previous timers
    clearTimeout(autoRestartTimer);
    clearInterval(countdownInterval);

    let remaining = AUTO_RESTART_DELAY;
    const cdEl = elCountdown();
    if (cdEl) cdEl.textContent = remaining;

    // Tick countdown every second
    countdownInterval = setInterval(() => {
        remaining--;
        const el = elCountdown();
        if (el) el.textContent = remaining;
        if (remaining <= 0) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
    }, 1000);

    // Auto restart after delay
    autoRestartTimer = setTimeout(() => {
        clearInterval(countdownInterval);
        countdownInterval = null;
        restartSession();
    }, AUTO_RESTART_DELAY * 1000);
}

function cancelAutoRestart() {
    clearTimeout(autoRestartTimer);
    clearInterval(countdownInterval);
    autoRestartTimer  = null;
    countdownInterval = null;
}

function handleUnlocked(data){
    resetNoFaceTimer();
    elUnlockName.textContent=data.name;
    elUnlockPct.textContent=`Kecocokan: ${Math.round(data.percentage)}%`;
    elUnlockOvr.classList.add('show');
    setLock(true, data.name, data.percentage);
    elStatus.className='status-badge green';
    elStatus.innerHTML='<i class="fas fa-check-circle"></i>Access Granted';

    // Setelah 3 detik: sembunyikan overlay ACCESS GRANTED, lanjut scanning
    // TIDAK tutup WS, TIDAK restart session — stream terus berjalan
    setTimeout(() => {
        elUnlockOvr.classList.remove('show');
        setLock(false);
        elStatus.className='status-badge green';
        elStatus.innerHTML='<i class="fas fa-circle"></i>Terhubung — Scanning…';
    }, 3000);

    addHistCard(data);
}

function addHistCard(e){
    elHistEmpty.style.display='none';
    const c=document.createElement('div'); c.className='history-card';
    const ts=new Date(e.timestamp);
    c.innerHTML=`${e.image?`<img src="${API_URL}/history/${e.image}" onerror="this.style.display='none'">`:''}
    <div class="flex-1 min-w-0">
      <div class="font-semibold text-sm text-white truncate">${e.name}</div>
      <div class="text-xs text-gray-400">${ts.toLocaleDateString('id-ID')} ${ts.toLocaleTimeString('id-ID')}</div>
      <div class="text-xs text-green-400 mt-0.5"><i class="fas fa-check-circle mr-1"></i>${Math.round(e.percentage)}% match</div>
    </div><i class="fas fa-door-open text-green-400 text-lg"></i>`;
    elHistList.insertBefore(c, elHistList.firstChild);
}

async function loadHistory(){
    try {
        const d=await(await fetch(`${API_URL}/api/history?limit=10`)).json();
        if(d.history?.length){ elHistEmpty.style.display='none'; d.history.forEach(addHistCard); }
    } catch {}
}

function restartSession(){
    lastResult=null;
    resetNoFaceTimer();
    cancelAutoRestart();
    elCamOff.classList.remove('show'); elUnlockOvr.classList.remove('show');
    elBtnRestart.classList.add('hidden'); setLock(false);
    elStatus.className='status-badge yellow';
    elStatus.innerHTML='<i class="fas fa-circle-notch fa-spin"></i>Menghubungkan…';
    canvas.width=320; canvas.height=240;
    ctx.fillStyle='#111827'; ctx.fillRect(0,0,320,240);
    ctx.fillStyle='#6b7280'; ctx.font='14px sans-serif'; ctx.textAlign='center';
    ctx.fillText('Memulai ulang…',160,120);
    setTimeout(startSession,300);
}
elBtnRestart.addEventListener('click', restartSession);
elBtnCamOff.addEventListener('click', restartSession);
</script>

<script>
function setConn(st){
    const m={connecting:['text-yellow-400','Menghubungkan…'],online:['text-green-400','Online'],offline:['text-red-400','Offline']};
    const[cls,txt]=m[st]||m.offline;
    elConnDot.className=`fas fa-circle text-[8px] ${cls}`;
    elConnText.textContent=txt; elConnText.className=`text-xs ${cls}`;
}

function startSession(){
    if(rcTimer){ clearTimeout(rcTimer); rcTimer=null; }
    if(ws && ws.readyState<=1) ws.close();
    setConn('connecting');
    ws=new WebSocket(WS_URL);
    ws.onopen=()=>{ rcAttempts=0; setConn('online');
        elStatus.className='status-badge green';
        elStatus.innerHTML='<i class="fas fa-circle"></i>Terhubung — Scanning…'; };
    ws.onmessage=({data:raw})=>{ let d; try{d=JSON.parse(raw);}catch{return;}
        if(d.type==='ping') ws.send('{"type":"pong"}');
        else if(d.type==='frame') displayFrame(d.image);
        else if(d.type==='result') updateRecogUI(d);
        else if(d.type==='unlocked_final') handleUnlocked(d);
        else if(d.type==='error'){
            elStatus.className='status-badge red';
            elStatus.innerHTML=`<i class="fas fa-times-circle"></i>${d.message}`; } };
    ws.onclose=()=>{ setConn('offline');
        elStatus.className='status-badge red';
        elStatus.innerHTML='<i class="fas fa-times"></i>Terputus';
        rcAttempts++;
        rcTimer=setTimeout(startSession, Math.min(1000*Math.pow(1.5,rcAttempts), MAX_RC)); };
    ws.onerror=()=>{};
}

// ═════════════════════════════════════════════════════════════════════════════
// YARD — CCTV / motion detection
// ═════════════════════════════════════════════════════════════════════════════
let motionFlashTimer=null;

function displayYard(b64){
    createImageBitmap(b64Blob(b64)).then(bmp=>{
        if(yardCanvas.width!==bmp.width||yardCanvas.height!==bmp.height){
            yardCanvas.width=bmp.width; yardCanvas.height=bmp.height;
        }
        yardCtx.drawImage(bmp,0,0); bmp.close();
        const fps=tickYard(); elYardFps.textContent=fps;
        elYardFps.className='val '+(fps<5?'fps-low':fps<12?'fps-mid':'fps-high');
    });
}

function handleMotion(data){
    // motion_ratio dari backend sudah dalam persen (0-100)
    const ratio = data.motion_ratio || 0;
    elMotionRatio.textContent = ratio.toFixed(1) + '%';

    if(data.motion){
        const now = Date.now();
        // Cooldown: tampilkan flash border setiap frame, tapi notifikasi log + badge
        // hanya dikirim sekali per MOTION_COOLDOWN_MS agar tidak spam
        elMotionFlash.classList.add('active');
        clearTimeout(motionFlashTimer);
        motionFlashTimer = setTimeout(() => elMotionFlash.classList.remove('active'), 800);

        if (now - lastMotionAlertAt >= MOTION_COOLDOWN_MS) {
            lastMotionAlertAt = now;

            // Badge ALERT di feed
            elMotionBadge.classList.remove('hidden');
            elMotionChip.className = 'status-badge red';
            elMotionChip.innerHTML = '<i class="fas fa-walking"></i><span class="font-semibold">Ada Tamu!</span>';

            // Update counter
            motionAlertCount++;
            if (elMotionCount) elMotionCount.textContent = motionAlertCount;
            if (elMotionCountChip) elMotionCountChip.classList.remove('hidden');

            clearTimeout(window._mbTimer);
            window._mbTimer = setTimeout(() => {
                elMotionBadge.classList.add('hidden');
                elMotionChip.className = 'status-badge green';
                elMotionChip.innerHTML = '<i class="fas fa-eye"></i><span>Memantau</span>';
            }, 5000);

            // Tambah ke log — hanya saat cooldown selesai
            addMotionCard(data);
        }
    }
}

function addMotionCard(data){
    elMotionEmpty.style.display='none';
    const c=document.createElement('div');
    c.className='motion-card alert';
    const ts=new Date().toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    const ratio = (data.motion_ratio||0).toFixed(1);
    c.innerHTML=`<div class="flex-1 min-w-0">
      <div class="text-xs font-semibold text-amber-400">
        <i class="fas fa-walking mr-1"></i>Ada Tamu Datang
      </div>
      <div class="text-xs text-gray-500">${ts} · ${ratio}% area bergerak</div>
    </div><i class="fas fa-person-walking text-amber-400 text-sm flex-shrink-0"></i>`;
    elMotionLogList.insertBefore(c, elMotionLogList.firstChild);
    while(elMotionLogList.children.length>50) elMotionLogList.lastChild.remove();
}
</script>

<script>
function setYardConn(st){
    const m={connecting:['text-yellow-400','Menghubungkan…'],online:['text-green-400','Memantau'],offline:['text-red-400','Offline']};
    const[cls,txt]=m[st]||m.offline;
    elYardConnDot.className=`fas fa-circle text-[8px] ${cls}`;
    elYardConnText.textContent=txt; elYardConnText.className=`text-xs ${cls}`;
    elYardStatus.className=`status-badge ${st==='online'?'green':st==='connecting'?'yellow':'red'}`;
    elYardStatus.innerHTML=st==='online'?'<i class="fas fa-eye"></i>Aktif'
        :st==='connecting'?'<i class="fas fa-circle-notch fa-spin"></i>Menghubungkan…'
        :'<i class="fas fa-times"></i>Terputus';
    elMotionChip.className=`status-badge ${st==='online'?'green':'gray'}`;
    elMotionChip.innerHTML=st==='online'
        ?'<i class="fas fa-eye"></i><span>Memantau</span>'
        :'<i class="fas fa-circle-notch fa-spin"></i><span>Menghubungkan…</span>';
}

function startMotion(){
    if(rcMotionTimer){ clearTimeout(rcMotionTimer); rcMotionTimer=null; }
    if(wsMotion && wsMotion.readyState<=1) wsMotion.close();
    setYardConn('connecting');
    wsMotion=new WebSocket(WS_MOT_URL);
    wsMotion.onopen=()=>{ rcMotionAttempts=0; setYardConn('online'); };
    wsMotion.onmessage=({data:raw})=>{ let d; try{d=JSON.parse(raw);}catch{return;}
        if(d.type==='ping') wsMotion.send('{"type":"pong"}');
        else if(d.type==='frame'){
            // ✅ FIX: backend mengirim motion data inline di dalam pesan type:"frame"
            // displayYard handles the video frame
            displayYard(d.image);
            // handleMotion checks d.motion flag — selalu dipanggil untuk update ratio
            handleMotion(d);
        }
        else if(d.type==='error'){
            setYardConn('offline');
            elYardStatus.innerHTML=`<i class="fas fa-times"></i>${d.message}`; } };
    wsMotion.onclose=()=>{ setYardConn('offline');
        rcMotionAttempts++;
        rcMotionTimer=setTimeout(startMotion, Math.min(1000*Math.pow(1.5,rcMotionAttempts), MAX_RC)); };
    wsMotion.onerror=()=>{};
}

document.getElementById('btn-clear-motion').addEventListener('click', async()=>{
    if(!confirm('Hapus seluruh log deteksi gerakan?')) return;
    elMotionLogList.innerHTML='';
    elMotionLogList.appendChild(elMotionEmpty);
    elMotionEmpty.style.display='';
    // Reset counter
    motionAlertCount = 0;
    if (elMotionCount) elMotionCount.textContent = '0';
    if (elMotionCountChip) elMotionCountChip.classList.add('hidden');
});

// ═════════════════════════════════════════════════════════════════════════════
// INIT
// ═════════════════════════════════════════════════════════════════════════════
canvas.width=320; canvas.height=240;
ctx.fillStyle='#111827'; ctx.fillRect(0,0,320,240);
ctx.fillStyle='#4b5563'; ctx.font='bold 14px sans-serif'; ctx.textAlign='center';
ctx.fillText('Menghubungkan ke kamera pintu…',160,115);

yardCanvas.width=320; yardCanvas.height=240;
yardCtx.fillStyle='#0d1117'; yardCtx.fillRect(0,0,320,240);
yardCtx.fillStyle='#374151'; yardCtx.font='bold 13px sans-serif'; yardCtx.textAlign='center';
yardCtx.fillText('Menghubungkan ke CCTV…',160,120);

loadCameras();
loadHistory();
startSession();
startMotion();
</script>
@endpush
