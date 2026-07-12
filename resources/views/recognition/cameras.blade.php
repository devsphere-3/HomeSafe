@extends('layouts.app')

@section('title', 'HomeSafe — Live Cameras')

@php
    $backendUrl = config('app.backend_url', 'http://127.0.0.1:5001');
    $wsUrl = str_replace(['https://', 'http://'], ['wss://', 'ws://'], $backendUrl);
@endphp

@push('styles')
<style>
/*
 * Layout: dua kamera kiri-kanan mengisi seluruh sisa tinggi layar setelah navbar.
 * Tidak ada scroll — semua masuk dalam satu viewport.
 */

/* Hapus padding bawah dari main agar benar-benar full-height */
main { padding-bottom: 0 !important; }

.cam-wrap {
    /* Tinggi = viewport dikurangi tinggi navbar (56px) dan padding atas (24px) */
    height: calc(100vh - 56px - 1.5rem);
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

/* ── Panel kamera ── */
.cam-panel {
    position: relative;
    background: rgba(8,17,33,0.80);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 14px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
html:not(.dark) .cam-panel {
    background: rgba(200,215,235,0.70);
    border-color: rgba(148,163,184,0.22);
}

/* ── Header strip di atas feed ── */
.cam-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.55rem 0.85rem;
    background: rgba(0,0,0,0.45);
    border-bottom: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0;
    z-index: 5;
}
html:not(.dark) .cam-header {
    background: rgba(180,200,225,0.55);
    border-bottom-color: rgba(148,163,184,0.18);
}

.cam-label {
    font-size: 0.78rem;
    font-weight: 600;
    color: #f1f5f9;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
html:not(.dark) .cam-label { color: #0f172a; }

/* ── Canvas mengisi semua sisa ruang ── */
.cam-canvas-wrap {
    flex: 1;
    position: relative;
    overflow: hidden;
}
.cam-canvas-wrap canvas {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: contain;
    background: #000;
}

/* ── Status badge kecil ── */
.s-badge {
    display: inline-flex; align-items: center; gap: 0.25rem;
    padding: 0.18rem 0.5rem;
    border-radius: 999px;
    font-size: 0.65rem; font-weight: 500;
    border: 1px solid transparent;
}
.s-badge.green  { background: rgba(16,185,129,0.18); border-color: rgba(16,185,129,0.35); color: #6ee7b7; }
.s-badge.yellow { background: rgba(234,179,8,0.15);  border-color: rgba(234,179,8,0.30);  color: #fde047; }
.s-badge.red    { background: rgba(239,68,68,0.15);  border-color: rgba(239,68,68,0.30);  color: #fca5a5; }
.s-badge.blue   { background: rgba(59,130,246,0.15); border-color: rgba(59,130,246,0.30); color: #93c5fd; }

/* ── Footer strip ── */
.cam-footer {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.85rem;
    background: rgba(0,0,0,0.40);
    border-top: 1px solid rgba(255,255,255,0.05);
    flex-shrink: 0;
    font-size: 0.68rem;
    color: #64748b;
}
html:not(.dark) .cam-footer {
    background: rgba(180,200,225,0.45);
    border-top-color: rgba(148,163,184,0.15);
    color: #64748b;
}
.cam-footer .chip {
    display: inline-flex; align-items: center; gap: 0.2rem;
    padding: 0.12rem 0.45rem;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 999px;
    color: #94a3b8;
    font-size: 0.65rem;
}
html:not(.dark) .cam-footer .chip {
    background: rgba(0,0,0,0.04); border-color: rgba(0,0,0,0.08); color: #475569;
}
.chip .val { font-weight: 600; color: #e2e8f0; }
html:not(.dark) .chip .val { color: #0f172a; }
.fps-high { color: #10b981; } .fps-mid { color: #f59e0b; } .fps-low { color: #ef4444; }

/* ── Overlays ── */
.motion-flash-full {
    position: absolute; inset: 0; pointer-events: none; z-index: 10;
    border: 4px solid transparent; transition: border-color 0.12s; border-radius: 14px;
}
.motion-flash-full.active {
    border-color: #f59e0b;
    box-shadow: inset 0 0 50px rgba(245,158,11,0.25);
}

.guest-banner {
    position: absolute; top: 10px; left: 50%; transform: translateX(-50%);
    z-index: 20; white-space: nowrap;
    background: rgba(245,158,11,0.92); color: #000;
    border-radius: 999px; padding: 0.3rem 0.9rem;
    font-size: 0.72rem; font-weight: 700;
    display: flex; align-items: center; gap: 0.35rem;
    box-shadow: 0 4px 20px rgba(245,158,11,0.5);
    opacity: 0; transition: opacity 0.25s;
    pointer-events: none;
}
.guest-banner.show { opacity: 1; }

/* ── Lock pill overlay (door cam) ── */
.lock-pill-full {
    position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%);
    z-index: 10; white-space: nowrap;
    display: inline-flex; align-items: center; gap: 0.4rem;
    padding: 0.35rem 0.9rem;
    border-radius: 999px;
    font-size: 0.72rem; font-weight: 600;
    backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1);
    transition: background 0.3s;
}
.lock-pill-full.locked   { background: rgba(15,23,42,0.85); color: #f1f5f9; }
.lock-pill-full.unlocked { background: rgba(16,185,129,0.25); border-color: rgba(16,185,129,0.4); color: #6ee7b7; }

/* ── Access granted full overlay ── */
.access-overlay {
    position: absolute; inset: 0; z-index: 30;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    background: rgba(0,0,0,0.55); backdrop-filter: blur(8px);
    opacity: 0; transition: opacity 0.35s; pointer-events: none;
}
.access-overlay.show { opacity: 1; }

/* ── Mobile: stack vertically ── */
@media (max-width: 640px) {
    .cam-wrap { grid-template-columns: 1fr; height: auto; }
}
</style>
@endpush

@section('content')
<div class="cam-wrap">

    {{-- ── KIRI: Kamera Pintu (Face Recognition) ── --}}
    <div class="cam-panel">

        {{-- Header --}}
        <div class="cam-header">
            <span class="cam-label">
                <i class="fas fa-door-open text-blue-400"></i>
                <span id="fc-title">Kamera Pintu</span>
            </span>
            <div class="flex items-center gap-1.5">
                <span id="fc-status" class="s-badge yellow">
                    <i class="fas fa-circle-notch fa-spin text-[8px]"></i>Menghubungkan…
                </span>
                <span id="fc-conn-dot" class="w-2 h-2 rounded-full bg-red-400 inline-block"></span>
            </div>
        </div>

        {{-- Canvas --}}
        <div class="cam-canvas-wrap">
            <canvas id="fc-canvas"></canvas>

            {{-- Access Granted overlay --}}
            <div id="fc-access-overlay" class="access-overlay">
                <i class="fas fa-check-circle text-4xl text-emerald-400 mb-2"></i>
                <div class="text-base font-bold text-emerald-400 tracking-wide">ACCESS GRANTED</div>
                <div id="fc-access-name" class="text-sm text-emerald-300 mt-1"></div>
            </div>

            {{-- Lock pill --}}
            <div id="fc-lock-pill" class="lock-pill-full locked">
                <i id="fc-lock-icon" class="fas fa-lock text-red-400 text-xs"></i>
                <span id="fc-lock-text" class="text-xs">LOCKED</span>
            </div>
        </div>

        {{-- Footer stats --}}
        <div class="cam-footer">
            <span class="chip">FPS <span class="val fps-high" id="fc-fps">0</span></span>
            <span class="chip">Wajah <span class="val" id="fc-faces">0</span></span>
            <span class="chip">Proses <span class="val" id="fc-proc">0</span>ms</span>
            <span id="fc-match-chip" class="chip hidden" style="border-color:rgba(16,185,129,0.3);">
                <i class="fas fa-user-check text-emerald-400 text-[10px]"></i>
                <span class="val" id="fc-match-name"></span>
            </span>
            <span class="ml-auto" id="fc-quality"></span>
        </div>
    </div>

    {{-- ── KANAN: Kamera CCTV (Motion Detection) ── --}}
    <div class="cam-panel">

        {{-- Header --}}
        <div class="cam-header">
            <span class="cam-label">
                <i class="fas fa-video text-emerald-400"></i>
                <span id="yc-title">Kamera CCTV</span>
            </span>
            <div class="flex items-center gap-1.5">
                <span id="yc-status" class="s-badge yellow">
                    <i class="fas fa-circle-notch fa-spin text-[8px]"></i>Menghubungkan…
                </span>
                <span id="yc-conn-dot" class="w-2 h-2 rounded-full bg-red-400 inline-block"></span>
            </div>
        </div>

        {{-- Canvas --}}
        <div class="cam-canvas-wrap">
            <canvas id="yc-canvas"></canvas>

            {{-- Motion flash border --}}
            <div id="yc-flash" class="motion-flash-full"></div>

            {{-- "Ada Tamu Datang" banner --}}
            <div id="yc-guest-banner" class="guest-banner">
                <i class="fas fa-walking"></i> Ada Tamu Datang!
            </div>
        </div>

        {{-- Footer stats --}}
        <div class="cam-footer">
            <span class="chip">FPS <span class="val fps-high" id="yc-fps">0</span></span>
            <span class="chip">Area gerak <span class="val" id="yc-ratio">0%</span></span>
            <span class="chip hidden" id="yc-alert-chip">
                <i class="fas fa-walking text-amber-400 text-[10px]"></i>
                <span class="val text-amber-400" id="yc-alert-count">0</span> tamu
            </span>
            <span id="yc-motion-badge" class="ml-auto hidden">
                <span class="s-badge red" style="animation: pulse 1s infinite;">
                    <i class="fas fa-walking"></i> Ada Tamu!
                </span>
            </span>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
// ── CONFIG ────────────────────────────────────────────────────────────────────
const API_URL    = '{{ $backendUrl }}';
const WS_DOOR    = '{{ $wsUrl }}/ws';
const WS_YARD    = '{{ $wsUrl }}/ws/motion';
const MAX_RC     = 15000;
const MOTION_COOLDOWN_MS = 8000;

// ── DOM — door ────────────────────────────────────────────────────────────────
const fcCanvas     = document.getElementById('fc-canvas');
const fcCtx        = fcCanvas.getContext('2d', { alpha: false });
const fcStatus     = document.getElementById('fc-status');
const fcConnDot    = document.getElementById('fc-conn-dot');
const fcFps        = document.getElementById('fc-fps');
const fcFaces      = document.getElementById('fc-faces');
const fcProc       = document.getElementById('fc-proc');
const fcMatchChip  = document.getElementById('fc-match-chip');
const fcMatchName  = document.getElementById('fc-match-name');
const fcQuality    = document.getElementById('fc-quality');
const fcLockPill   = document.getElementById('fc-lock-pill');
const fcLockIcon   = document.getElementById('fc-lock-icon');
const fcLockText   = document.getElementById('fc-lock-text');
const fcAccessOvr  = document.getElementById('fc-access-overlay');
const fcAccessName = document.getElementById('fc-access-name');
const fcTitle      = document.getElementById('fc-title');

// ── DOM — yard ────────────────────────────────────────────────────────────────
const ycCanvas     = document.getElementById('yc-canvas');
const ycCtx        = ycCanvas.getContext('2d', { alpha: false });
const ycStatus     = document.getElementById('yc-status');
const ycConnDot    = document.getElementById('yc-conn-dot');
const ycFps        = document.getElementById('yc-fps');
const ycRatio      = document.getElementById('yc-ratio');
const ycFlash      = document.getElementById('yc-flash');
const ycGuest      = document.getElementById('yc-guest-banner');
const ycBadge      = document.getElementById('yc-motion-badge');
const ycAlertChip  = document.getElementById('yc-alert-chip');
const ycAlertCount = document.getElementById('yc-alert-count');
const ycTitle      = document.getElementById('yc-title');

// ── STATE ─────────────────────────────────────────────────────────────────────
let wsDoor = null, wsYard = null;
let rcDoor = null,  rcYard = null;
let rcDoorAtt = 0,  rcYardAtt = 0;
let doorLocked = true, doorLastResult = null;
let yardAlertCount = 0, lastYardAlert = 0;
let flashTimer = null, guestTimer = null, badgeTimer = null;

// ── FPS helpers ───────────────────────────────────────────────────────────────
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

function fpsClass(fps) {
    return fps<5?'fps-low':fps<15?'fps-mid':'fps-high';
}
</script>

<script>
// ── LOAD CAMERA NAMES FROM API ────────────────────────────────────────────────
async function loadCameraNames() {
    try {
        const d = await (await fetch(`${API_URL}/api/cameras`)).json();
        const cams = d.cameras || [];
        const doorCam = cams.find(c => c.id === d.door_cam_id);
        const yardCam = cams.find(c => c.id === d.yard_cam_id);
        if (doorCam) fcTitle.textContent = `${doorCam.name} (${doorCam.node})`;
        if (yardCam) ycTitle.textContent = `${yardCam.name} (${yardCam.node})`;
    } catch { /* backend belum siap, nama default tetap tampil */ }
}

// ── DOOR CAMERA — Face Recognition ───────────────────────────────────────────
function setDoorConn(st) {
    const map = {
        connecting : ['yellow','circle-notch fa-spin','Menghubungkan…','bg-yellow-400'],
        online     : ['green', 'circle',              'Online',        'bg-green-400'],
        offline    : ['red',   'times-circle',        'Offline',       'bg-red-400'],
    };
    const [badge, icon, txt, dot] = map[st] || map.offline;
    fcStatus.className = `s-badge ${badge}`;
    fcStatus.innerHTML = `<i class="fas fa-${icon} text-[8px]"></i>${txt}`;
    fcConnDot.className = `w-2 h-2 rounded-full ${dot} inline-block`;
}

function setDoorLock(ok, name='', pct=0) {
    if (ok) {
        fcLockPill.className = 'lock-pill-full unlocked';
        fcLockIcon.className = 'fas fa-lock-open text-emerald-400 text-xs';
        fcLockText.textContent = `UNLOCKED — ${name} ${Math.round(pct)}%`;
    } else {
        fcLockPill.className = 'lock-pill-full locked';
        fcLockIcon.className = 'fas fa-lock text-red-400 text-xs';
        fcLockText.textContent = 'LOCKED';
    }
}

function drawDoorFrame(b64) {
    createImageBitmap(b64Blob(b64)).then(bmp => {
        if (fcCanvas.width!==bmp.width||fcCanvas.height!==bmp.height) {
            fcCanvas.width=bmp.width; fcCanvas.height=bmp.height;
        }
        fcCtx.drawImage(bmp,0,0); bmp.close();
        const fps = tickDoor();
        fcFps.textContent = fps;
        fcFps.className = 'val ' + fpsClass(fps);
        if (doorLastResult) drawDoorBbox(doorLastResult);
    });
}

function drawDoorBbox(data) {
    if (!data.bbox || !data.face_detected) return;
    const {xmin:x=0,ymin:y=0,width:w=0,height:h=0} = data.bbox;
    const color = data.matched ? '#22c55e' : '#ef4444';
    fcCtx.strokeStyle=color; fcCtx.lineWidth=3;
    fcCtx.shadowColor=color; fcCtx.shadowBlur=14;
    fcCtx.strokeRect(x,y,w,h); fcCtx.shadowBlur=0;
    const label = data.matched ? `${data.name}  ${Math.round(data.percentage)}%` : 'Unknown';
    fcCtx.font='bold 12px system-ui';
    const tw = fcCtx.measureText(label).width;
    fcCtx.fillStyle = data.matched ? 'rgba(0,60,20,.8)' : 'rgba(60,0,0,.8)';
    fcCtx.fillRect(x,y-22,tw+12,20);
    fcCtx.fillStyle='#fff'; fcCtx.fillText(label,x+6,y-6);
}

function updateDoorResult(data) {
    doorLastResult = data;
    fcFaces.textContent = data.face_count || 0;
    fcProc.textContent  = data.process_time_ms || 0;
    drawDoorBbox(data);

    const qmap = {too_small:'Terlalu jauh',too_close:'Terlalu dekat',multiple_faces:'Banyak wajah'};
    if (data.quality_issue) {
        fcStatus.className = 's-badge yellow';
        fcStatus.innerHTML = `<i class="fas fa-exclamation-triangle text-[8px]"></i>${qmap[data.quality_issue]||data.quality_issue}`;
        fcQuality.innerHTML = '';
    } else if (data.face_detected) {
        fcStatus.className = 's-badge blue';
        fcStatus.innerHTML = '<i class="fas fa-face-smile text-[8px]"></i>Wajah terdeteksi';
        fcQuality.innerHTML = '';
    } else {
        fcStatus.className = 's-badge yellow';
        fcStatus.innerHTML = '<i class="fas fa-circle-notch fa-spin text-[8px]"></i>Scanning…';
        doorLastResult = null;
    }

    if (data.face_detected && data.matched) {
        fcMatchChip.classList.remove('hidden');
        fcMatchChip.style.display = 'inline-flex';
        fcMatchName.textContent = `${data.name} (${Math.round(data.percentage)}%)`;
        setDoorLock(true, data.name, data.percentage);
    } else {
        fcMatchChip.classList.add('hidden');
        if (!data.unlocked) setDoorLock(false);
    }
}

function handleDoorUnlocked(data) {
    doorLocked = true;
    fcAccessName.textContent = `${data.name} — ${Math.round(data.percentage)}%`;
    fcAccessOvr.classList.add('show');
    setDoorLock(true, data.name, data.percentage);
    fcStatus.className = 's-badge green';
    fcStatus.innerHTML = '<i class="fas fa-check-circle text-[8px]"></i>Access Granted';
    setTimeout(() => {
        fcAccessOvr.classList.remove('show');
        doorLocked = false;
        setDoorLock(false);
    }, 4000);
}
</script>

<script>
// ── YARD CAMERA — CCTV Motion ─────────────────────────────────────────────────
function setYardConn(st) {
    const map = {
        connecting : ['yellow','circle-notch fa-spin','Menghubungkan…','bg-yellow-400'],
        online     : ['green', 'eye',                 'Memantau',      'bg-green-400'],
        offline    : ['red',   'times-circle',        'Offline',       'bg-red-400'],
    };
    const [badge, icon, txt, dot] = map[st] || map.offline;
    ycStatus.className = `s-badge ${badge}`;
    ycStatus.innerHTML = `<i class="fas fa-${icon} text-[8px]"></i>${txt}`;
    ycConnDot.className = `w-2 h-2 rounded-full ${dot} inline-block`;
}

function drawYardFrame(b64) {
    createImageBitmap(b64Blob(b64)).then(bmp => {
        if (ycCanvas.width!==bmp.width||ycCanvas.height!==bmp.height) {
            ycCanvas.width=bmp.width; ycCanvas.height=bmp.height;
        }
        ycCtx.drawImage(bmp,0,0); bmp.close();
        const fps = tickYard();
        ycFps.textContent = fps;
        ycFps.className = 'val ' + fpsClass(fps);
    });
}

function handleYardMotion(data) {
    const ratio = data.motion_ratio || 0;
    ycRatio.textContent = ratio.toFixed(1) + '%';

    if (!data.motion) return;

    // Flash border setiap frame ada gerakan
    ycFlash.classList.add('active');
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => ycFlash.classList.remove('active'), 800);

    // Notifikasi "Ada Tamu Datang" — cooldown agar tidak spam
    const now = Date.now();
    if (now - lastYardAlert < MOTION_COOLDOWN_MS) return;
    lastYardAlert = now;

    // Banner overlay "Ada Tamu Datang"
    ycGuest.classList.add('show');
    clearTimeout(guestTimer);
    guestTimer = setTimeout(() => ycGuest.classList.remove('show'), 4000);

    // Badge di header
    ycBadge.classList.remove('hidden');
    ycStatus.className = 's-badge red';
    ycStatus.innerHTML = '<i class="fas fa-walking text-[8px]"></i>Ada Tamu!';
    clearTimeout(badgeTimer);
    badgeTimer = setTimeout(() => {
        ycBadge.classList.add('hidden');
        ycStatus.className = 's-badge green';
        ycStatus.innerHTML = '<i class="fas fa-eye text-[8px]"></i>Memantau';
    }, 5000);

    // Counter tamu
    yardAlertCount++;
    ycAlertCount.textContent = yardAlertCount;
    ycAlertChip.classList.remove('hidden');
}
</script>

<script>
// ── WEBSOCKET — DOOR ──────────────────────────────────────────────────────────
function startDoor() {
    if (rcDoor) { clearTimeout(rcDoor); rcDoor = null; }
    if (wsDoor && wsDoor.readyState <= 1) wsDoor.close();
    setDoorConn('connecting');
    wsDoor = new WebSocket(WS_DOOR);

    wsDoor.onopen = () => { rcDoorAtt = 0; setDoorConn('online'); };

    wsDoor.onmessage = ({data: raw}) => {
        let d; try { d = JSON.parse(raw); } catch { return; }
        if (d.type === 'ping')           wsDoor.send('{"type":"pong"}');
        else if (d.type === 'frame')     drawDoorFrame(d.image);
        else if (d.type === 'result')    updateDoorResult(d);
        else if (d.type === 'unlocked_final') handleDoorUnlocked(d);
        else if (d.type === 'error') {
            setDoorConn('offline');
            fcStatus.className = 's-badge red';
            fcStatus.innerHTML = `<i class="fas fa-times text-[8px]"></i>${d.message}`;
        }
    };

    wsDoor.onclose = () => {
        setDoorConn('offline');
        rcDoorAtt++;
        rcDoor = setTimeout(startDoor, Math.min(1000 * Math.pow(1.5, rcDoorAtt), MAX_RC));
    };
    wsDoor.onerror = () => {};
}

// ── WEBSOCKET — YARD ──────────────────────────────────────────────────────────
function startYard() {
    if (rcYard) { clearTimeout(rcYard); rcYard = null; }
    if (wsYard && wsYard.readyState <= 1) wsYard.close();
    setYardConn('connecting');
    wsYard = new WebSocket(WS_YARD);

    wsYard.onopen = () => { rcYardAtt = 0; setYardConn('online'); };

    wsYard.onmessage = ({data: raw}) => {
        let d; try { d = JSON.parse(raw); } catch { return; }
        if (d.type === 'ping')       wsYard.send('{"type":"pong"}');
        else if (d.type === 'frame') {
            drawYardFrame(d.image);
            handleYardMotion(d);
        }
        else if (d.type === 'error') {
            setYardConn('offline');
        }
    };

    wsYard.onclose = () => {
        setYardConn('offline');
        rcYardAtt++;
        rcYard = setTimeout(startYard, Math.min(1000 * Math.pow(1.5, rcYardAtt), MAX_RC));
    };
    wsYard.onerror = () => {};
}

// ── INIT ──────────────────────────────────────────────────────────────────────
// Placeholder saat koneksi belum ada
(function initCanvases() {
    fcCanvas.width = 640; fcCanvas.height = 480;
    fcCtx.fillStyle = '#0a1628';
    fcCtx.fillRect(0,0,640,480);
    fcCtx.fillStyle = '#334155'; fcCtx.font = 'bold 14px system-ui';
    fcCtx.textAlign = 'center';
    fcCtx.fillText('Menghubungkan ke kamera pintu…', 320, 240);

    ycCanvas.width = 640; ycCanvas.height = 480;
    ycCtx.fillStyle = '#050d1a';
    ycCtx.fillRect(0,0,640,480);
    ycCtx.fillStyle = '#1e3a5f'; ycCtx.font = 'bold 14px system-ui';
    ycCtx.textAlign = 'center';
    ycCtx.fillText('Menghubungkan ke CCTV…', 320, 240);
})();

loadCameraNames();
startDoor();
startYard();
</script>
@endpush
