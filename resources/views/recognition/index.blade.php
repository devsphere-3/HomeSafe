@extends('layouts.app')

@section('title', 'Smart Lock - Face Recognition')

@php
    $backendUrl = config('app.backend_url', 'http://127.0.0.1:5001');
    $wsUrl = str_replace(['https://', 'http://'], ['wss://', 'ws://'], $backendUrl);
@endphp

@push('styles')
<style>
    .panel { background: #1f2937; border-radius: 0.5rem; padding: 1rem; }
    .panel-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem; }
    .panel-title { font-weight: 600; font-size: 0.95rem; display: flex; align-items: center; gap: 0.4rem; }
    .cam-feed {
        position: relative; background: #0d1117; border-radius: 0.5rem;
        overflow: hidden; aspect-ratio: 4/3; min-height: 220px;
    }
    .cam-feed canvas { position: absolute; inset: 0; width: 100%; height: 100%; }
    .badge-alert { position: absolute; top: 8px; right: 8px; z-index: 10; }
    .lock-bar { position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%); z-index: 10; }
    #motion-flash {
        position: absolute; inset: 0; pointer-events: none; z-index: 15;
        border: 4px solid transparent; transition: border-color 0.1s;
    }
    #motion-flash.active { border-color: #ef4444; box-shadow: inset 0 0 40px rgba(239,68,68,0.4); }
    #unlock-overlay, #camera-off-overlay {
        position: absolute; inset: 0; display: flex; flex-direction: column;
        align-items: center; justify-content: center; z-index: 20;
        pointer-events: none; opacity: 0; transition: opacity 0.4s;
        background: rgba(0,0,0,0.55); backdrop-filter: blur(4px);
    }
    #unlock-overlay.show, #camera-off-overlay.show { opacity: 1; pointer-events: auto; }
    .check-icon { font-size: 4rem; color: #22c55e; }
    .stats-row { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.75rem; font-size: 0.75rem; color: #9ca3af; }
    .stat-chip { background: rgba(255,255,255,0.05); padding: 0.25rem 0.6rem; border-radius: 0.375rem; }
    .stat-chip .val { font-weight: 600; color: #e5e7eb; }
    .fps-high { color: #22c55e; } .fps-mid { color: #f59e0b; } .fps-low { color: #ef4444; }
    .btn-sm { padding: 0.4rem 0.75rem; border-radius: 0.375rem; font-size: 0.8rem; cursor: pointer; border: none; transition: background .15s; }
    .btn-blue  { background: #2563eb; color: #fff; } .btn-blue:hover  { background: #1d4ed8; }
    .btn-green { background: #16a34a; color: #fff; } .btn-green:hover { background: #15803d; }
    .btn-red   { background: #dc2626; color: #fff; } .btn-red:hover   { background: #b91c1c; }
    .btn-gray  { background: #374151; color: #fff; } .btn-gray:hover  { background: #4b5563; }
    .log-panel { background: #111827; border-radius: 0.5rem; padding: 0.75rem; max-height: 280px; overflow-y: auto; }
    .log-panel h3 { font-size: 0.85rem; font-weight: 600; margin-bottom: 0.5rem; color: #d1d5db; }
    .history-card, .motion-card {
        display: flex; align-items: center; gap: 0.75rem;
        padding: 0.5rem 0.75rem; background: #1f2937; border-radius: 0.375rem;
        margin-bottom: 0.4rem; border-left: 3px solid #374151;
    }
    .history-card img { width: 48px; height: 36px; object-fit: cover; border-radius: 0.25rem; }
    .motion-card.alert { border-left-color: #ef4444; background: rgba(127,29,29,0.2); }
    .conn-bar { display: flex; align-items: center; gap: 1rem; font-size: 0.75rem; margin-bottom: 1rem; color: #9ca3af; }
    .grid-main { display: grid; grid-template-columns: 1fr 320px; gap: 1rem; }
    @media (max-width: 1024px) { .grid-main { grid-template-columns: 1fr; } }
    /* cam-select */
    .cam-select {
        background: #374151; border: 1px solid #4b5563; color: #fff;
        padding: 0.3rem 0.6rem; border-radius: 0.375rem; font-size: 0.75rem;
        min-width: 200px; max-width: 320px;
    }
    .cam-select:disabled { opacity: .5; cursor: not-allowed; }
    option:disabled { color: #6b7280; }
</style>
@endpush

@section('content')

{{-- Connection status bar --}}
<div class="conn-bar">
    <span>
        <i id="conn-dot" class="fas fa-circle text-[8px] text-red-400"></i>
        <span id="conn-text" class="text-xs text-red-400 ml-1">Offline</span> — Kamera Pintu
    </span>
    <span>
        <i id="yard-conn-dot" class="fas fa-circle text-[8px] text-red-400"></i>
        <span id="yard-conn-text" class="text-xs text-red-400 ml-1">Offline</span> — CCTV
    </span>
    <span class="text-xs text-gray-500 ml-auto">Backend: {{ $backendUrl }}</span>
</div>

{{-- Camera selector panel --}}
<div class="panel mb-4">
    <div class="flex flex-wrap items-center gap-3 mb-2">
        {{-- Door --}}
        <div class="flex items-center gap-2">
            <i class="fas fa-door-open text-blue-400 text-sm"></i>
            <label class="text-xs text-gray-300 whitespace-nowrap">Pintu (Face Recognition):</label>
            <select id="door-cam-select" class="cam-select" disabled>
                <option value="">Memuat…</option>
            </select>
            <button id="btn-apply-door" class="btn-sm btn-blue">
                <i class="fas fa-check mr-1"></i>Terapkan
            </button>
        </div>
        <span class="text-gray-600 hidden sm:inline">|</span>
        {{-- Yard --}}
        <div class="flex items-center gap-2">
            <i class="fas fa-video text-green-400 text-sm"></i>
            <label class="text-xs text-gray-300 whitespace-nowrap">CCTV (Motion):</label>
            <select id="yard-cam-select" class="cam-select" disabled>
                <option value="">Memuat…</option>
            </select>
            <button id="btn-apply-yard" class="btn-sm btn-blue">
                <i class="fas fa-check mr-1"></i>Terapkan
            </button>
        </div>
        <button id="btn-refresh-cameras" class="btn-sm btn-gray ml-auto">
            <i class="fas fa-sync-alt mr-1"></i>Refresh
        </button>
    </div>
    <p id="cam-selector-note" class="text-xs text-gray-500">
        <i class="fas fa-spinner fa-spin mr-1"></i>Mendeteksi kamera…
    </p>
</div>

<div class="grid-main">
    {{-- Left: dual camera feeds --}}
    <div class="space-y-4">

        {{-- DOOR — face recognition --}}
        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">
                    <i class="fas fa-door-open text-blue-400"></i>Kamera Pintu — Face Recognition
                </span>
                <span id="recognition-status" class="px-2 py-1 text-xs rounded bg-yellow-700 flex items-center gap-1">
                    <i class="fas fa-circle-notch fa-spin"></i>Menghubungkan…
                </span>
            </div>
            <div class="cam-feed">
                <canvas id="overlay-canvas"></canvas>
                <div id="unlock-overlay">
                    <div class="check-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="text-xl font-bold text-green-400 mt-2">ACCESS GRANTED</div>
                    <div id="unlock-name-display" class="text-green-300 mt-1"></div>
                    <div id="unlock-pct-display" class="text-green-400/70 text-sm mt-1"></div>
                </div>
                <div id="camera-off-overlay">
                    <i class="fas fa-camera-slash text-4xl text-gray-400 mb-3"></i>
                    <p class="text-gray-300 text-sm mb-3">Sesi selesai</p>
                    <button id="btn-cam-off-restart" class="btn-sm btn-blue pointer-events-auto">Mulai Ulang</button>
                </div>
                <div class="lock-bar">
                    <div id="lock-status" class="inline-flex items-center px-4 py-2 rounded-lg bg-gray-900/80 backdrop-blur-sm gap-2">
                        <i id="lock-icon" class="fas fa-lock text-xl text-red-400"></i>
                        <span id="lock-text">LOCKED</span>
                        <span id="user-info" class="hidden text-xs text-green-300"></span>
                    </div>
                </div>
            </div>
            <div class="stats-row">
                <span class="stat-chip">Wajah: <span class="val" id="face-count">0</span></span>
                <span class="stat-chip">Proses: <span class="val" id="process-time">0</span>ms</span>
                <span class="stat-chip">FPS: <span class="val fps-high" id="fps-counter">0</span></span>
                <span class="stat-chip hidden" id="user-indicator-wrapper">
                    <i class="fas fa-user-check text-green-400 mr-1"></i>
                    <span class="val" id="user-indicator"></span>
                </span>
            </div>
            <button id="btn-restart-scan" class="btn-sm btn-gray mt-2 hidden">
                <i class="fas fa-redo mr-1"></i>Scan Ulang
            </button>
        </div>

        {{-- YARD — CCTV motion detection --}}
        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">
                    <i class="fas fa-video text-green-400"></i>CCTV — Deteksi Gerakan
                </span>
                <div class="flex items-center gap-2">
                    <span id="motion-status-chip" class="px-2 py-1 text-xs rounded bg-gray-700 flex items-center gap-1">
                        <i class="fas fa-circle-notch fa-spin text-gray-400"></i>
                        <span class="text-gray-400">Menghubungkan…</span>
                    </span>
                    <span id="yard-status" class="px-2 py-1 text-xs rounded bg-yellow-700 flex items-center gap-1">
                        <i class="fas fa-circle-notch fa-spin"></i>Menghubungkan…
                    </span>
                </div>
            </div>
            <div class="cam-feed">
                <canvas id="yard-canvas"></canvas>
                <div id="motion-flash"></div>
                <span id="motion-alert-badge" class="badge-alert hidden px-2 py-1 text-xs rounded bg-red-600 font-bold animate-pulse">
                    <i class="fas fa-exclamation-triangle mr-1"></i>ALERT
                </span>
            </div>
            <div class="stats-row">
                <span class="stat-chip">FPS: <span class="val fps-high" id="yard-fps">0</span></span>
                <span class="stat-chip">Objek: <span class="val" id="motion-count">0</span></span>
                <span class="stat-chip">Area gerak: <span class="val" id="motion-ratio">0%</span></span>
            </div>
        </div>
    </div>

    {{-- Right: logs --}}
    <div class="space-y-4">
        <div class="log-panel">
            <h3><i class="fas fa-door-open text-green-400 mr-1"></i>Riwayat Akses</h3>
            <div id="history-list"></div>
            <p id="history-empty" class="text-xs text-gray-500 text-center py-4">Belum ada akses tercatat</p>
        </div>
        <div class="log-panel">
            <div class="flex items-center justify-between mb-2">
                <h3><i class="fas fa-bell text-red-400 mr-1"></i>Log Deteksi Gerakan</h3>
                <button id="btn-clear-motion" class="text-xs text-gray-400 hover:text-white">
                    <i class="fas fa-trash mr-1"></i>Hapus
                </button>
            </div>
            <div id="motion-log-list"></div>
            <p id="motion-log-empty" class="text-xs text-gray-500 text-center py-4">Belum ada gerakan terdeteksi</p>
        </div>
    </div>
</div>
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
const elMotionCount   = document.getElementById('motion-count');
const elMotionRatio   = document.getElementById('motion-ratio');
const elYardConnDot   = document.getElementById('yard-conn-dot');
const elYardConnText  = document.getElementById('yard-conn-text');
const elMotionLogList = document.getElementById('motion-log-list');
const elMotionEmpty   = document.getElementById('motion-log-empty');

// ── DOM — selector ────────────────────────────────────────────────────────────
const elDoorSel = document.getElementById('door-cam-select');
const elYardSel = document.getElementById('yard-cam-select');
const elNote    = document.getElementById('cam-selector-note');

// ── STATE ─────────────────────────────────────────────────────────────────────
let ws = null, wsMotion = null;
let rcTimer = null, rcMotionTimer = null;
let rcAttempts = 0, rcMotionAttempts = 0;
let sessionLocked = false, lastResult = null;

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
        elIndWrap.classList.remove('hidden'); elIndWrap.style.display='inline-flex';
        elInd.textContent=`${data.name} (${Math.round(data.percentage)}%)`;
    } else elIndWrap.classList.add('hidden');
    data.unlocked?setLock(true,data.name,data.percentage):setLock(false);
    const qmap={too_small:'Terlalu jauh',too_close:'Terlalu dekat',multiple_faces:'Beberapa wajah'};
    if (data.quality_issue){
        elStatus.className='px-2 py-1 text-xs rounded bg-orange-600 flex items-center gap-1';
        elStatus.innerHTML=`<i class="fas fa-exclamation-triangle"></i>${qmap[data.quality_issue]||data.quality_issue}`;
    } else if (data.face_detected){
        elStatus.className='px-2 py-1 text-xs rounded bg-blue-600 flex items-center gap-1';
        elStatus.innerHTML='<i class="fas fa-face-smile"></i>Wajah terdeteksi';
    } else {
        elStatus.className='px-2 py-1 text-xs rounded bg-yellow-600 flex items-center gap-1';
        elStatus.innerHTML='<i class="fas fa-circle-notch fa-spin"></i>Scanning…';
        lastResult=null; elIndWrap.classList.add('hidden');
    }
}

function setLock(ok,name='',pct=0){
    if (ok){
        elLockIcon.className='fas fa-lock-open text-xl text-green-400';
        elLockText.textContent='UNLOCKED';
        elLockStatus.className='inline-flex items-center px-4 py-2 rounded-lg bg-green-900/80 backdrop-blur-sm gap-2';
        elUserInfo.textContent=`${name}  ${Math.round(pct)}%`;
        elUserInfo.className='text-xs text-green-300'; elUserInfo.classList.remove('hidden');
    } else {
        elLockIcon.className='fas fa-lock text-xl text-red-400';
        elLockText.textContent='LOCKED';
        elLockStatus.className='inline-flex items-center px-4 py-2 rounded-lg bg-gray-900/80 backdrop-blur-sm gap-2';
        elUserInfo.classList.add('hidden');
    }
}

function handleUnlocked(data){
    sessionLocked=true;
    elUnlockName.textContent=data.name;
    elUnlockPct.textContent=`Kecocokan: ${Math.round(data.percentage)}%`;
    elUnlockOvr.classList.add('show'); setLock(true,data.name,data.percentage);
    elStatus.className='px-2 py-1 text-xs rounded bg-green-600 flex items-center gap-1';
    elStatus.innerHTML='<i class="fas fa-check-circle"></i>Access Granted';
    setTimeout(()=>{
        elUnlockOvr.classList.remove('show');
        elCamOff.classList.add('show');
        elBtnRestart.classList.remove('hidden');
        ws?.close();
    },3200);
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
    sessionLocked=false; lastResult=null;
    elCamOff.classList.remove('show'); elUnlockOvr.classList.remove('show');
    elBtnRestart.classList.add('hidden'); setLock(false);
    elStatus.className='px-2 py-1 text-xs rounded bg-yellow-600 flex items-center gap-1';
    elStatus.innerHTML='<i class="fas fa-circle-notch fa-spin"></i>Menghubungkan…';
    canvas.width=320; canvas.height=240;
    ctx.fillStyle='#111827'; ctx.fillRect(0,0,320,240);
    ctx.fillStyle='#6b7280'; ctx.font='14px sans-serif'; ctx.textAlign='center';
    ctx.fillText('Memulai ulang…',160,120);
    setTimeout(startSession,300);
}
elBtnRestart.addEventListener('click', restartSession);
elBtnCamOff.addEventListener('click', restartSession);

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
        elStatus.className='px-2 py-1 text-xs rounded bg-green-600 flex items-center gap-1';
        elStatus.innerHTML='<i class="fas fa-circle"></i>Terhubung — Scanning…'; };
    ws.onmessage=({data:raw})=>{ let d; try{d=JSON.parse(raw);}catch{return;}
        if(d.type==='ping') ws.send('{"type":"pong"}');
        else if(d.type==='frame') displayFrame(d.image);
        else if(d.type==='result') updateRecogUI(d);
        else if(d.type==='unlocked_final') handleUnlocked(d);
        else if(d.type==='error'){
            elStatus.className='px-2 py-1 text-xs rounded bg-red-600 flex items-center gap-1';
            elStatus.innerHTML=`<i class="fas fa-times-circle"></i>${d.message}`; } };
    ws.onclose=()=>{ setConn('offline'); if(sessionLocked) return;
        elStatus.className='px-2 py-1 text-xs rounded bg-red-600 flex items-center gap-1';
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

function drawMotionBoxes(cnts){
    if(!cnts?.length) return;
    yardCtx.strokeStyle='#ef4444'; yardCtx.lineWidth=2;
    yardCtx.shadowColor='#ef4444'; yardCtx.shadowBlur=8;
    cnts.forEach(b=>yardCtx.strokeRect(b.x,b.y,b.w,b.h));
    yardCtx.shadowBlur=0;
}

function handleMotion(data){
    elMotionCount.textContent=data.contours?.length||0;
    elMotionRatio.textContent=((data.motion_ratio||0)*100).toFixed(1)+'%';
    drawMotionBoxes(data.contours);
    if(data.alert){
        elMotionFlash.classList.add('active');
        clearTimeout(motionFlashTimer);
        motionFlashTimer=setTimeout(()=>elMotionFlash.classList.remove('active'),800);
        elMotionBadge.classList.remove('hidden');
        elMotionChip.className='px-2 py-1 text-xs rounded bg-red-700 flex items-center gap-1';
        elMotionChip.innerHTML='<i class="fas fa-exclamation-triangle text-white"></i><span class="text-white font-semibold">ALERT</span>';
        clearTimeout(window._mbTimer);
        window._mbTimer=setTimeout(()=>{
            elMotionBadge.classList.add('hidden');
            elMotionChip.className='px-2 py-1 text-xs rounded bg-green-700 flex items-center gap-1';
            elMotionChip.innerHTML='<i class="fas fa-eye text-white"></i><span class="text-white">Memantau</span>';
        },5000);
        addMotionCard(data);
    }
}

function addMotionCard(data){
    elMotionEmpty.style.display='none';
    const c=document.createElement('div');
    c.className='motion-card'+(data.alert?' alert':'');
    const ts=new Date().toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    c.innerHTML=`<div class="flex-1 min-w-0">
      <div class="text-xs font-semibold ${data.alert?'text-red-400':'text-yellow-400'}">
        <i class="fas fa-${data.alert?'exclamation-triangle':'circle-dot'} mr-1"></i>
        ${data.alert?'Objek Terdeteksi':'Gerakan Terdeteksi'}
      </div>
      <div class="text-xs text-gray-500">${ts} · ${data.contours?.length||0} objek · ${((data.motion_ratio||0)*100).toFixed(1)}%</div>
    </div><i class="fas fa-bell ${data.alert?'text-red-400':'text-yellow-600'} text-sm flex-shrink-0"></i>`;
    elMotionLogList.insertBefore(c, elMotionLogList.firstChild);
    while(elMotionLogList.children.length>50) elMotionLogList.lastChild.remove();
}

function setYardConn(st){
    const m={connecting:['text-yellow-400','Menghubungkan…'],online:['text-green-400','Memantau'],offline:['text-red-400','Offline']};
    const[cls,txt]=m[st]||m.offline;
    elYardConnDot.className=`fas fa-circle text-[8px] ${cls}`;
    elYardConnText.textContent=txt; elYardConnText.className=`text-xs ${cls}`;
    const bg=st==='online'?'bg-green-700':st==='connecting'?'bg-yellow-700':'bg-red-700';
    elYardStatus.className=`px-2 py-1 text-xs rounded flex items-center gap-1 ${bg}`;
    elYardStatus.innerHTML=st==='online'?'<i class="fas fa-eye"></i>Aktif'
        :st==='connecting'?'<i class="fas fa-circle-notch fa-spin"></i>Menghubungkan…'
        :'<i class="fas fa-times"></i>Terputus';
    elMotionChip.className=`px-2 py-1 text-xs rounded ${st==='online'?'bg-green-700':'bg-gray-700'} flex items-center gap-1`;
    elMotionChip.innerHTML=st==='online'
        ?'<i class="fas fa-eye text-white"></i><span class="text-white">Memantau</span>'
        :'<i class="fas fa-circle-notch fa-spin text-gray-400"></i><span class="text-gray-400">Menghubungkan…</span>';
}

function startMotion(){
    if(rcMotionTimer){ clearTimeout(rcMotionTimer); rcMotionTimer=null; }
    if(wsMotion && wsMotion.readyState<=1) wsMotion.close();
    setYardConn('connecting');
    wsMotion=new WebSocket(WS_MOT_URL);
    wsMotion.onopen=()=>{ rcMotionAttempts=0; setYardConn('online'); };
    wsMotion.onmessage=({data:raw})=>{ let d; try{d=JSON.parse(raw);}catch{return;}
        if(d.type==='ping') wsMotion.send('{"type":"pong"}');
        else if(d.type==='frame') displayYard(d.image);
        else if(d.type==='motion') handleMotion(d);
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
