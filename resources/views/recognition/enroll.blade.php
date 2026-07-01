@extends('layouts.app')

@section('title', 'Enroll Wajah - HomeSafe')

@php
    $backendUrl = config('app.backend_url', env('BACKEND_URL', 'http://127.0.0.1:5001'));
    $wsUrl      = str_replace(['https://', 'http://'], ['wss://', 'ws://'], $backendUrl);
@endphp

@push('styles')
<style>
/* Canvas is the ONLY display — no hidden <video> trick */
#enroll-canvas {
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: #000;
}
.camera-container {
    position: relative;
    background: #000;
    border-radius: 0.5rem;
    overflow: hidden;
    aspect-ratio: 4/3;
}
/* Guide box drawn on canvas — just CSS guide overlay */
.face-guide {
    position: absolute;
    inset: 0;
    display: flex; align-items: center; justify-content: center;
    pointer-events: none; z-index: 5;
}
.face-guide-box {
    width: 45%; aspect-ratio: 3/4;
    border: 2px dashed rgba(59,130,246,.5);
    border-radius: 50%;
    transition: border-color .3s;
}
.face-guide-box.active { border-color: rgba(34,197,94,.7); }
/* Status chip */
.status-chip {
    position: absolute; top: 10px; left: 10px; z-index: 10;
}
/* Progress ring */
.ring-wrap { position: relative; width: 56px; height: 56px; flex-shrink: 0; }
.ring-wrap svg { transform: rotate(-90deg); }
.ring-bg  { fill: none; stroke: #374151; stroke-width: 5; }
.ring-fill{ fill: none; stroke: #3b82f6; stroke-width: 5;
            stroke-linecap: round;
            stroke-dasharray: 157; stroke-dashoffset: 157;
            transition: stroke-dashoffset .4s ease; }
.ring-text { position:absolute;inset:0;display:flex;align-items:center;
             justify-content:center;font-size:11px;font-weight:700; }
</style>
@endpush

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="bg-gray-800 rounded-xl p-6">

        <h2 class="text-xl font-bold mb-1 flex items-center gap-2">
            <i class="fas fa-user-plus text-blue-400"></i>Daftarkan Wajah Baru
        </h2>
        <p class="text-sm text-gray-400 mb-5">
            Posisikan wajah di dalam lingkaran panduan. Sistem akan mengambil
            <strong class="text-white">{{ config('app.registration_frames', 10) }}</strong>
            sampel untuk membuat profil biometrik.
        </p>

        {{-- Name form --}}
        <form id="enroll-form" class="mb-4" autocomplete="off">
            <div class="flex gap-3">
                <input type="text" id="user-name" placeholder="Masukkan nama…" required
                    maxlength="50" autocomplete="off" spellcheck="false"
                    class="flex-1 px-4 py-2 rounded bg-gray-700 border border-gray-600
                           focus:border-blue-500 focus:outline-none text-white text-sm">
                <button type="submit" id="btn-start"
                    class="px-5 py-2 bg-blue-600 hover:bg-blue-700 rounded font-semibold transition text-sm">
                    <i class="fas fa-play mr-1"></i>Mulai
                </button>
                <button type="button" id="btn-cancel"
                    class="px-5 py-2 bg-red-600 hover:bg-red-700 rounded font-semibold transition text-sm hidden">
                    <i class="fas fa-stop mr-1"></i>Batal
                </button>
            </div>
        </form>

        {{-- Camera --}}
        <div class="camera-container mb-4">
            <canvas id="enroll-canvas"></canvas>
            <div class="face-guide"><div class="face-guide-box" id="guide-box"></div></div>
            <div class="status-chip">
                <span id="enroll-status-chip"
                    class="px-2 py-1 text-xs rounded bg-gray-600 flex items-center gap-1">
                    <i class="fas fa-circle-notch fa-spin"></i>Menghubungkan…
                </span>
            </div>
        </div>

        {{-- Progress bar --}}
        <div id="progress-area" class="mb-4 hidden">
            <div class="flex items-center gap-4">
                {{-- Ring progress --}}
                <div class="ring-wrap">
                    <svg viewBox="0 0 56 56" width="56" height="56">
                        <circle class="ring-bg"   cx="28" cy="28" r="25"/>
                        <circle class="ring-fill" cx="28" cy="28" r="25" id="ring-fill"/>
                    </svg>
                    <div class="ring-text" id="ring-text">0%</div>
                </div>
                {{-- Bar --}}
                <div class="flex-1">
                    <div class="flex justify-between text-xs mb-1">
                        <span id="progress-message" class="text-gray-300">Mengambil data…</span>
                        <span id="progress-count"   class="text-gray-400">0 / 10</span>
                    </div>
                    <div class="w-full bg-gray-700 rounded-full h-2.5">
                        <div id="progress-bar"
                            class="bg-blue-500 h-2.5 rounded-full transition-all duration-300"
                            style="width: 0%"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Status message --}}
        <div id="enroll-status" class="hidden px-4 py-3 rounded text-sm"></div>

        {{-- Tips --}}
        <div class="mt-5 grid grid-cols-2 gap-3 text-xs text-gray-500">
            <div class="flex items-start gap-2">
                <i class="fas fa-lightbulb text-yellow-400 mt-0.5"></i>
                Pencahayaan cukup & merata di wajah
            </div>
            <div class="flex items-start gap-2">
                <i class="fas fa-eye text-blue-400 mt-0.5"></i>
                Tatap langsung ke kamera
            </div>
            <div class="flex items-start gap-2">
                <i class="fas fa-user text-green-400 mt-0.5"></i>
                Hanya 1 wajah dalam frame
            </div>
            <div class="flex items-start gap-2">
                <i class="fas fa-arrows-alt text-purple-400 mt-0.5"></i>
                Jaga jarak ±30–60 cm dari kamera
            </div>
        </div>

    </div>
</div>
@endsection

@push('scripts')
<script>
const WS_URL  = '{{ $wsUrl }}/ws/enroll';
const API_URL = '{{ $backendUrl }}';

const canvas    = document.getElementById('enroll-canvas');
const ctx       = canvas.getContext('2d', { alpha: false });
const guideBox  = document.getElementById('guide-box');
const ringFill  = document.getElementById('ring-fill');
const ringText  = document.getElementById('ring-text');
const elChip    = document.getElementById('enroll-status-chip');

let ws = null;
let isScanning       = false;
let reconnectTimer   = null;
let reconnectAttempts = 0;
let scanTimer        = null;
let lastBbox         = null;
const MAX_RECONNECT  = 15000;
const RING_CIRC      = 157;  // 2π × 25

// ── Helpers ───────────────────────────────────────────────────────────────────
function showStatus(msg, colorClass) {
    const el = document.getElementById('enroll-status');
    el.className = `px-4 py-3 rounded text-sm text-white ${colorClass}`;
    el.innerHTML = msg;
    el.classList.remove('hidden');
}
function setChip(msg, color) {
    elChip.className = `px-2 py-1 text-xs rounded ${color} flex items-center gap-1`;
    elChip.innerHTML = msg;
}
function updateProgress(data) {
    document.getElementById('progress-area').classList.remove('hidden');
    const pct = data.progress || 0;
    document.getElementById('progress-bar').style.width    = pct + '%';
    document.getElementById('progress-count').textContent  = `${data.count} / ${data.total}`;
    document.getElementById('progress-message').textContent = data.message || 'Mengambil data…';
    ringFill.style.strokeDashoffset = RING_CIRC - (RING_CIRC * pct / 100);
    ringText.textContent = pct + '%';
    guideBox.classList.add('active');
}

// ── Frame display ─────────────────────────────────────────────────────────────
function displayFrame(b64) {
    const bin = atob(b64), arr = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    createImageBitmap(new Blob([arr], { type: 'image/jpeg' })).then(bmp => {
        if (canvas.width !== bmp.width || canvas.height !== bmp.height) {
            canvas.width = bmp.width; canvas.height = bmp.height;
        }
        ctx.drawImage(bmp, 0, 0);
        bmp.close();
        if (lastBbox) _drawBbox(lastBbox);
    });
}

// ── Bbox drawing ──────────────────────────────────────────────────────────────
function _drawBbox(b) {
    const x = b.xmin ?? b.x ?? 0;
    const y = b.ymin ?? b.y ?? 0;
    const w = b.width  ?? 0;
    const h = b.height ?? 0;
    const color = isScanning ? '#22c55e' : '#3b82f6';
    ctx.strokeStyle = color;
    ctx.lineWidth   = 3;
    ctx.shadowColor = color;
    ctx.shadowBlur  = 10;
    ctx.strokeRect(x, y, w, h);
    ctx.shadowBlur  = 0;

    // Label: quality hint
    const label = isScanning ? 'Mengambil…' : 'Wajah OK';
    ctx.font = 'bold 12px system-ui';
    const tw = ctx.measureText(label).width;
    ctx.fillStyle = 'rgba(0,0,0,.65)';
    ctx.fillRect(x, y - 22, tw + 12, 20);
    ctx.fillStyle = '#fff';
    ctx.fillText(label, x + 6, y - 6);
}

function handlePreview(data) {
    lastBbox = data.bbox || null;
    if (lastBbox) _drawBbox(lastBbox);
    else           guideBox.classList.remove('active');

    // Chip update
    if (data.quality_issue) {
        const map = { too_small:'Terlalu jauh', too_close:'Terlalu dekat', multiple_faces:'Beberapa wajah' };
        setChip(`<i class="fas fa-exclamation-triangle"></i>${map[data.quality_issue] || data.quality_issue}`, 'bg-orange-600');
    } else if (data.face_count > 0) {
        setChip('<i class="fas fa-face-smile"></i>Wajah terdeteksi', 'bg-blue-600');
    } else {
        setChip('<i class="fas fa-circle-notch fa-spin"></i>Mencari wajah…', 'bg-gray-600');
    }
}

// ── Scan loop ─────────────────────────────────────────────────────────────────
function startScanLoop() {
    stopScanLoop();
    scanTimer = setInterval(() => {
        if (ws && ws.readyState === WebSocket.OPEN)
            ws.send(JSON.stringify({ type: 'scan' }));
    }, 200);
}
function stopScanLoop() {
    if (scanTimer) { clearInterval(scanTimer); scanTimer = null; }
}

// ── WebSocket ─────────────────────────────────────────────────────────────────
function connect() {
    if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
    if (ws && ws.readyState <= 1) ws.close();

    ws = new WebSocket(WS_URL);

    ws.onopen = () => {
        reconnectAttempts = 0;
        setChip('<i class="fas fa-circle"></i>Terhubung', 'bg-green-600');
        showStatus('<i class="fas fa-check-circle mr-2"></i>Kamera aktif. Masukkan nama lalu klik Mulai.', 'bg-green-700');
        startScanLoop();
    };

    ws.onmessage = ({ data: raw }) => {
        let data;
        try { data = JSON.parse(raw); } catch { return; }

        switch (data.type) {
            case 'frame':
                displayFrame(data.image);
                break;
            case 'preview':
                handlePreview(data);
                break;
            case 'warn':
                showStatus(`<i class="fas fa-triangle-exclamation mr-2"></i>${data.message}`, 'bg-yellow-700');
                break;
            case 'register_progress':
                updateProgress(data);
                showStatus(`<i class="fas fa-spinner fa-spin mr-2"></i>${data.message}`, 'bg-blue-700');
                break;
            case 'register_success':
                showStatus(`<i class="fas fa-check-circle mr-2"></i>Berhasil! Profil '<strong>${data.name}</strong>' tersimpan.`, 'bg-green-700');
                isScanning = false;
                guideBox.classList.remove('active');
                lastBbox = null;
                document.getElementById('btn-cancel').classList.add('hidden');
                document.getElementById('btn-start').classList.remove('hidden');
                document.getElementById('progress-area').classList.add('hidden');
                document.getElementById('user-name').value = '';
                ringFill.style.strokeDashoffset = RING_CIRC;
                ringText.textContent = '0%';
                break;
            case 'register_error':
                showStatus(`<i class="fas fa-times-circle mr-2"></i>${data.message}`, 'bg-red-700');
                isScanning = false;
                document.getElementById('btn-cancel').classList.add('hidden');
                document.getElementById('btn-start').classList.remove('hidden');
                document.getElementById('progress-area').classList.add('hidden');
                break;
            case 'status':
                showStatus(`<i class="fas fa-info-circle mr-2"></i>${data.message}`, 'bg-blue-700');
                break;
            case 'error':
                showStatus(`<i class="fas fa-times-circle mr-2"></i>${data.message}`, 'bg-red-700');
                setChip('<i class="fas fa-times"></i>Error', 'bg-red-600');
                break;
        }
    };

    ws.onclose = () => {
        stopScanLoop();
        setChip('<i class="fas fa-times"></i>Terputus', 'bg-red-600');
        showStatus('<i class="fas fa-wifi mr-2"></i>Koneksi terputus. Mencoba kembali…', 'bg-red-700');
        reconnectAttempts++;
        const delay = Math.min(1000 * Math.pow(1.5, reconnectAttempts), MAX_RECONNECT);
        reconnectTimer = setTimeout(connect, delay);
    };

    ws.onerror = () => {};
}

// ── Event listeners ───────────────────────────────────────────────────────────
document.getElementById('enroll-form').addEventListener('submit', e => {
    e.preventDefault();
    const name = document.getElementById('user-name').value.trim();
    if (!name) {
        showStatus('<i class="fas fa-exclamation-circle mr-2"></i>Masukkan nama terlebih dahulu.', 'bg-yellow-700');
        return;
    }
    if (!ws || ws.readyState !== WebSocket.OPEN) {
        showStatus('<i class="fas fa-wifi mr-2"></i>Belum terhubung ke server. Tunggu sebentar…', 'bg-red-700');
        return;
    }
    isScanning = true;
    ws.send(JSON.stringify({ type: 'register_start', name }));
    document.getElementById('btn-start').classList.add('hidden');
    document.getElementById('btn-cancel').classList.remove('hidden');
    document.getElementById('progress-area').classList.remove('hidden');
    showStatus(`<i class="fas fa-spinner fa-spin mr-2"></i>Memulai scan untuk '<strong>${name}</strong>'…`, 'bg-blue-700');
});

document.getElementById('btn-cancel').addEventListener('click', () => {
    isScanning = false;
    lastBbox   = null;
    guideBox.classList.remove('active');
    if (ws && ws.readyState === WebSocket.OPEN)
        ws.send(JSON.stringify({ type: 'register_cancel' }));
    document.getElementById('btn-start').classList.remove('hidden');
    document.getElementById('btn-cancel').classList.add('hidden');
    document.getElementById('progress-area').classList.add('hidden');
    ringFill.style.strokeDashoffset = RING_CIRC;
    ringText.textContent = '0%';
    showStatus('<i class="fas fa-ban mr-2"></i>Pendaftaran dibatalkan.', 'bg-yellow-700');
});

// ── INIT ──────────────────────────────────────────────────────────────────────
canvas.width = 320; canvas.height = 240;
ctx.fillStyle = '#111827'; ctx.fillRect(0, 0, 320, 240);
ctx.fillStyle = '#4b5563'; ctx.font = '14px sans-serif';
ctx.textAlign = 'center';
ctx.fillText('Menghubungkan ke kamera…', 160, 120);

connect();
</script>
@endpush
