@extends('layouts.app')

@section('title', 'Daftarkan Wajah — HomeSafe')

@php
    $backendUrl = config('app.backend_url', env('BACKEND_URL', 'http://127.0.0.1:5001'));
    $wsUrl      = str_replace(['https://', 'http://'], ['wss://', 'ws://'], $backendUrl);
@endphp

@push('styles')
<style>
    .enroll-glass {
        background: rgba(12, 24, 45, 0.70);
        backdrop-filter: blur(24px) saturate(1.4);
        -webkit-backdrop-filter: blur(24px) saturate(1.4);
        border: 1px solid rgba(255,255,255,0.09);
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 16px 48px rgba(0,0,0,0.35), inset 0 1px 0 rgba(255,255,255,0.07);
    }
    html:not(.dark) .enroll-glass {
        background: rgba(210, 222, 240, 0.72);
        border-color: rgba(148,163,184,0.25);
        box-shadow: 0 8px 32px rgba(15,23,42,0.12), inset 0 1px 0 rgba(255,255,255,0.55);
    }

    /* Camera container */
    .cam-wrap {
        position: relative;
        background: #020617;
        border-radius: 14px;
        overflow: hidden;
        aspect-ratio: 4/3;
    }
    html:not(.dark) .cam-wrap { background: #0f172a; }
    #enroll-canvas {
        position: absolute; inset: 0;
        width: 100%; height: 100%;
    }

    /* Face guide overlay */
    .face-guide {
        position: absolute; inset: 0;
        display: flex; align-items: center; justify-content: center;
        pointer-events: none; z-index: 5;
    }
    .face-guide-box {
        width: 44%; aspect-ratio: 3/4;
        border: 1.5px dashed rgba(59,130,246,0.45);
        border-radius: 50%;
        transition: border-color 0.3s, box-shadow 0.3s;
    }
    .face-guide-box.active {
        border-color: rgba(16,185,129,0.7);
        box-shadow: 0 0 24px rgba(16,185,129,0.15);
    }

    /* Status chip in camera */
    .cam-chip {
        position: absolute; top: 10px; left: 10px; z-index: 10;
        padding: 5px 10px; border-radius: 7px;
        font-size: 0.7rem; font-weight: 500;
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        border: 1px solid rgba(255,255,255,0.1);
        display: flex; align-items: center; gap: 5px;
    }

    .name-input {
        flex: 1;
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(255,255,255,0.11);
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 0.875rem;
        color: #f1f5f9;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
        font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .name-input::placeholder { color: #475569; }
    .name-input:focus {
        border-color: rgba(59,130,246,0.6);
        box-shadow: 0 0 0 3px rgba(59,130,246,0.12);
    }
    html:not(.dark) .name-input {
        background: rgba(15,23,42,0.07);
        border-color: rgba(71,85,105,0.30);
        color: #0f172a;
    }
    html:not(.dark) .name-input::placeholder { color: #64748b; }
    html:not(.dark) .name-input:focus {
        border-color: rgba(37,99,235,0.5);
        box-shadow: 0 0 0 3px rgba(37,99,235,0.10);
    }

    /* Buttons */
    .btn-start {
        padding: 10px 20px; border-radius: 10px;
        font-size: 0.875rem; font-weight: 600;
        background: #3b82f6; color: #fff; border: none;
        cursor: pointer; transition: all 0.2s ease;
        font-family: 'Plus Jakarta Sans', sans-serif;
        white-space: nowrap;
        display: flex; align-items: center; gap: 6px;
    }
    .btn-start:hover { background: #2563eb; transform: scale(1.02); box-shadow: 0 4px 16px rgba(59,130,246,0.3); }
    .btn-cancel {
        padding: 10px 20px; border-radius: 10px;
        font-size: 0.875rem; font-weight: 600;
        background: rgba(239,68,68,0.12);
        border: 1px solid rgba(239,68,68,0.2);
        color: #f87171; cursor: pointer;
        transition: all 0.2s ease;
        font-family: 'Plus Jakarta Sans', sans-serif;
        white-space: nowrap;
        display: flex; align-items: center; gap: 6px;
    }
    .btn-cancel:hover { background: rgba(239,68,68,0.22); transform: scale(1.02); }

    /* Ring progress */
    .ring-wrap { position: relative; width: 52px; height: 52px; flex-shrink: 0; }
    .ring-wrap svg { transform: rotate(-90deg); }
    .ring-bg   { fill: none; stroke: rgba(255,255,255,0.08); stroke-width: 4; }
    html:not(.dark) .ring-bg { stroke: rgba(0,0,0,0.08); }
    .ring-fill { fill: none; stroke: #3b82f6; stroke-width: 4;
                 stroke-linecap: round;
                 stroke-dasharray: 157; stroke-dashoffset: 157;
                 transition: stroke-dashoffset 0.4s ease; }
    .ring-text {
        position: absolute; inset: 0;
        display: flex; align-items: center; justify-content: center;
        font-size: 10px; font-weight: 700; color: #f1f5f9;
    }
    html:not(.dark) .ring-text { color: #0f172a; }

    /* Progress bar */
    .prog-track {
        height: 4px; background: rgba(255,255,255,0.07);
        border-radius: 99px; overflow: hidden;
    }
    html:not(.dark) .prog-track { background: rgba(0,0,0,0.07); }
    .prog-fill {
        height: 100%; background: #3b82f6; border-radius: 99px;
        transition: width 0.3s ease;
    }

    /* Status message */
    .status-msg {
        border-radius: 10px; padding: 10px 14px;
        font-size: 0.8125rem; border: 1px solid transparent;
        display: flex; align-items: flex-start; gap: 8px;
    }
    .status-success { background: rgba(16,185,129,0.1); border-color: rgba(16,185,129,0.2); color: #6ee7b7; }
    .status-info    { background: rgba(59,130,246,0.1); border-color: rgba(59,130,246,0.2); color: #93c5fd; }
    .status-warn    { background: rgba(245,158,11,0.1); border-color: rgba(245,158,11,0.2); color: #fcd34d; }
    .status-error   { background: rgba(239,68,68,0.1);  border-color: rgba(239,68,68,0.2);  color: #fca5a5; }

    /* ── Password gate modal ── */
    .pw-modal-backdrop {
        position: fixed; inset: 0; z-index: 100;
        background: rgba(8,15,30,0.75);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        display: flex; align-items: center; justify-content: center;
        padding: 1rem;
    }
    .pw-modal {
        background: rgba(12, 24, 45, 0.90);
        backdrop-filter: blur(28px) saturate(1.5);
        -webkit-backdrop-filter: blur(28px) saturate(1.5);
        border: 1px solid rgba(255,255,255,0.10);
        border-radius: 20px;
        padding: 32px;
        width: 100%; max-width: 380px;
        box-shadow: 0 24px 64px rgba(0,0,0,0.5), inset 0 1px 0 rgba(255,255,255,0.07);
    }
    html:not(.dark) .pw-modal {
        background: rgba(210,222,240,0.92);
        border-color: rgba(148,163,184,0.28);
        box-shadow: 0 16px 48px rgba(15,23,42,0.18);
    }
    .pw-input {
        width: 100%;
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(255,255,255,0.12);
        border-radius: 10px;
        padding: 11px 16px;
        font-size: 0.9rem;
        color: #f1f5f9;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
        font-family: 'Plus Jakarta Sans', sans-serif;
        letter-spacing: 0.1em;
    }
    .pw-input::placeholder { color: #475569; letter-spacing: normal; }
    .pw-input:focus {
        border-color: rgba(59,130,246,0.6);
        box-shadow: 0 0 0 3px rgba(59,130,246,0.12);
    }
    html:not(.dark) .pw-input {
        background: rgba(15,23,42,0.07);
        border-color: rgba(71,85,105,0.30);
        color: #0f172a;
    }
    html:not(.dark) .pw-input:focus {
        border-color: rgba(37,99,235,0.5);
        box-shadow: 0 0 0 3px rgba(37,99,235,0.10);
    }
    .pw-shake {
        animation: pw-shake 0.4s ease;
    }
    @keyframes pw-shake {
        0%,100% { transform: translateX(0); }
        20%      { transform: translateX(-8px); }
        40%      { transform: translateX(8px); }
        60%      { transform: translateX(-5px); }
        80%      { transform: translateX(5px); }
    }
    .tip-card {
        background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 10px; padding: 10px 12px;
        display: flex; align-items: flex-start; gap: 8px;
        font-size: 0.75rem; color: #64748b;
    }
    html:not(.dark) .tip-card {
        background: rgba(0,0,0,0.02);
        border-color: rgba(0,0,0,0.06);
    }
</style>
@endpush

@section('content')

{{-- ── Password gate modal ── --}}
<div id="pw-gate" class="pw-modal-backdrop">
    <div class="pw-modal" id="pw-modal-box">
        <!-- Lock icon -->
        <div class="flex flex-col items-center mb-6">
            <div class="w-14 h-14 rounded-2xl flex items-center justify-center mb-4"
                 style="background:rgba(59,130,246,0.12);border:1px solid rgba(59,130,246,0.2);">
                <i class="fas fa-lock text-blue-400 text-xl"></i>
            </div>
            <h2 class="text-primary font-bold text-lg">Akses Dibatasi</h2>
            <p class="text-secondary text-sm text-center mt-1">
                Masukkan sandi admin untuk mendaftarkan anggota baru.
            </p>
        </div>

        <!-- Password input -->
        <div class="space-y-3">
            <div class="relative">
                <input type="password" id="pw-input" class="pw-input" placeholder="Masukkan sandi…"
                       autocomplete="off" maxlength="64">
                <button type="button" id="pw-toggle-vis"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-secondary hover:text-primary transition-colors text-sm"
                    tabindex="-1" aria-label="Tampilkan sandi">
                    <i class="fas fa-eye" id="pw-eye-icon"></i>
                </button>
            </div>

            <div id="pw-error" class="hidden text-xs text-red-400 flex items-center gap-1.5">
                <i class="fas fa-circle-exclamation"></i>
                <span>Sandi salah. Coba lagi.</span>
            </div>

            <button id="pw-submit"
                class="w-full py-2.5 rounded-10px font-semibold text-sm text-white transition-all duration-200"
                style="background:#3b82f6;border-radius:10px;">
                <i class="fas fa-unlock-keyhole mr-2"></i>Masuk
            </button>

            <a href="{{ route('home') }}"
               class="block text-center text-xs text-secondary hover:text-primary transition-colors mt-1">
                Kembali ke Monitoring
            </a>
        </div>
    </div>
</div>

{{-- ── Enroll content (hidden until password verified) ── --}}
<div id="enroll-content" class="hidden">
<div class="max-w-2xl mx-auto">
    <div class="enroll-glass">
        <!-- Header -->
        <div class="px-6 pt-6 pb-4 border-b border-white/[0.06]">
            <h1 class="text-lg font-bold text-primary flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-lg bg-blue-500/15 border border-blue-500/20 flex items-center justify-center text-blue-400 text-sm">
                    <i class="fas fa-user-plus"></i>
                </span>
                Daftarkan Wajah Baru
            </h1>
            <p class="text-secondary text-sm mt-1.5">
                Posisikan wajah dalam lingkaran panduan.
                Sistem mengambil <strong class="text-primary font-semibold">{{ config('app.registration_frames', 10) }}</strong> sampel biometrik.
            </p>
        </div>

        <div class="p-6 space-y-5">
            <!-- Name form -->
            <form id="enroll-form" autocomplete="off">
                <div class="flex gap-2">
                    <input type="text" id="user-name" placeholder="Masukkan nama pengguna…"
                        required maxlength="50" autocomplete="off" spellcheck="false"
                        class="name-input">
                    <button type="submit" id="btn-start" class="btn-start">
                        <i class="fas fa-play text-xs"></i>Mulai
                    </button>
                    <button type="button" id="btn-cancel" class="btn-cancel hidden">
                        <i class="fas fa-stop text-xs"></i>Batal
                    </button>
                </div>
            </form>

            <!-- Camera feed -->
            <div class="cam-wrap">
                <canvas id="enroll-canvas"></canvas>
                <div class="face-guide"><div class="face-guide-box" id="guide-box"></div></div>
                <div id="enroll-status-chip" class="cam-chip bg-slate-800/70 text-slate-400">
                    <i class="fas fa-circle-notch fa-spin text-[10px]"></i>Menghubungkan…
                </div>
            </div>

            <!-- Progress -->
            <div id="progress-area" class="hidden">
                <div class="flex items-center gap-4">
                    <div class="ring-wrap">
                        <svg viewBox="0 0 52 52" width="52" height="52">
                            <circle class="ring-bg"   cx="26" cy="26" r="22"/>
                            <circle class="ring-fill" cx="26" cy="26" r="22" id="ring-fill"/>
                        </svg>
                        <div class="ring-text" id="ring-text">0%</div>
                    </div>
                    <div class="flex-1">
                        <div class="flex justify-between text-xs mb-1.5">
                            <span id="progress-message" class="text-secondary">Mengambil data…</span>
                            <span id="progress-count"   class="text-secondary font-medium">0 / 10</span>
                        </div>
                        <div class="prog-track">
                            <div id="progress-bar" class="prog-fill" style="width:0%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status message -->
            <div id="enroll-status" class="hidden status-msg"></div>

            <!-- Tips -->
            <div class="grid grid-cols-2 gap-2 pt-1">
                <div class="tip-card">
                    <i class="fas fa-lightbulb text-amber-400 mt-0.5 text-xs flex-shrink-0"></i>
                    Pencahayaan merata, hindari backlight
                </div>
                <div class="tip-card">
                    <i class="fas fa-eye text-blue-400 mt-0.5 text-xs flex-shrink-0"></i>
                    Tatap langsung ke kamera
                </div>
                <div class="tip-card">
                    <i class="fas fa-user text-emerald-400 mt-0.5 text-xs flex-shrink-0"></i>
                    Hanya 1 wajah dalam frame
                </div>
                <div class="tip-card">
                    <i class="fas fa-arrows-alt text-violet-400 mt-0.5 text-xs flex-shrink-0"></i>
                    Jarak optimal 30–60 cm
                </div>
            </div>
        </div>
    </div>
</div>{{-- end enroll-content --}}

@endsection

@push('scripts')
<script>
const WS_URL  = '{{ $wsUrl }}/ws/enroll';
const API_URL = '{{ $backendUrl }}';

const canvas   = document.getElementById('enroll-canvas');
const ctx      = canvas.getContext('2d', { alpha: false });
const guideBox = document.getElementById('guide-box');
const ringFill = document.getElementById('ring-fill');
const ringText = document.getElementById('ring-text');
const elChip   = document.getElementById('enroll-status-chip');

let ws = null, isScanning = false;
let reconnectTimer = null, reconnectAttempts = 0, scanTimer = null, lastBbox = null;
const MAX_RECONNECT = 15000;
const RING_CIRC     = Math.PI * 2 * 22; // ~138.2

function showStatus(msg, type = 'info') {
    const el = document.getElementById('enroll-status');
    const map = { success: 'status-success', info: 'status-info', warn: 'status-warn', error: 'status-error' };
    const icons = { success: 'fa-check-circle', info: 'fa-info-circle', warn: 'fa-triangle-exclamation', error: 'fa-times-circle' };
    el.className = `status-msg ${map[type]||'status-info'}`;
    el.innerHTML = `<i class="fas ${icons[type]||'fa-info-circle'} mt-0.5 flex-shrink-0"></i><span>${msg}</span>`;
    el.classList.remove('hidden');
}

function setChip(html, bg = 'bg-slate-800/70', color = 'text-slate-400') {
    elChip.className = `cam-chip ${bg} ${color}`;
    elChip.innerHTML = html;
}

function updateProgress(data) {
    document.getElementById('progress-area').classList.remove('hidden');
    const pct = data.progress || 0;
    document.getElementById('progress-bar').style.width = pct + '%';
    document.getElementById('progress-count').textContent = `${data.count} / ${data.total}`;
    document.getElementById('progress-message').textContent = data.message || 'Mengambil data…';
    ringFill.style.strokeDashoffset = RING_CIRC * (1 - pct / 100);
    ringFill.setAttribute('stroke-dasharray', RING_CIRC);
    ringText.textContent = pct + '%';
    guideBox.classList.add('active');
}

function displayFrame(b64) {
    const bin = atob(b64), arr = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    createImageBitmap(new Blob([arr], { type:'image/jpeg' })).then(bmp => {
        if (canvas.width !== bmp.width || canvas.height !== bmp.height) {
            canvas.width = bmp.width; canvas.height = bmp.height;
        }
        ctx.drawImage(bmp, 0, 0); bmp.close();
        if (lastBbox) drawBbox(lastBbox);
    });
}

function drawBbox(b) {
    const x = b.xmin??b.x??0, y = b.ymin??b.y??0, w = b.width??0, h = b.height??0;
    const color = isScanning ? '#10b981' : '#3b82f6';
    ctx.strokeStyle = color; ctx.lineWidth = 2.5;
    ctx.shadowColor = color; ctx.shadowBlur = 12;
    ctx.strokeRect(x, y, w, h); ctx.shadowBlur = 0;
    const label = isScanning ? 'Mengambil…' : 'Wajah OK';
    ctx.font = 'bold 11px Plus Jakarta Sans, system-ui';
    const tw = ctx.measureText(label).width;
    ctx.fillStyle = 'rgba(0,0,0,0.65)'; ctx.fillRect(x, y-20, tw+12, 18);
    ctx.fillStyle = '#fff'; ctx.fillText(label, x+6, y-6);
}

function handlePreview(data) {
    lastBbox = data.bbox || null;
    if (lastBbox) drawBbox(lastBbox);
    else guideBox.classList.remove('active');
    if (data.quality_issue) {
        const map = { too_small:'Terlalu jauh', too_close:'Terlalu dekat', multiple_faces:'Beberapa wajah' };
        setChip(`<i class="fas fa-triangle-exclamation text-[10px]"></i>${map[data.quality_issue]||data.quality_issue}`, 'bg-amber-500/20', 'text-amber-400');
    } else if (data.face_count > 0) {
        setChip('<i class="fas fa-circle-check text-[10px]"></i>Wajah terdeteksi', 'bg-blue-500/20', 'text-blue-400');
    } else {
        setChip('<i class="fas fa-circle-notch fa-spin text-[10px]"></i>Mencari wajah…', 'bg-slate-800/70', 'text-slate-400');
    }
}

function startScanLoop() {
    stopScanLoop();
    scanTimer = setInterval(() => {
        if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify({ type: 'scan' }));
    }, 200);
}
function stopScanLoop() { if (scanTimer) { clearInterval(scanTimer); scanTimer = null; } }

function connect() {
    if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
    if (ws && ws.readyState <= 1) ws.close();
    ws = new WebSocket(WS_URL);

    ws.onopen = () => {
        reconnectAttempts = 0;
        setChip('<i class="fas fa-circle text-[8px]"></i>Terhubung', 'bg-emerald-500/20', 'text-emerald-400');
        showStatus('Kamera aktif. Masukkan nama lalu klik Mulai.', 'success');
        startScanLoop();
    };

    ws.onmessage = ({ data: raw }) => {
        let d; try { d = JSON.parse(raw); } catch { return; }
        switch (d.type) {
            case 'frame':             displayFrame(d.image); break;
            case 'preview':           handlePreview(d); break;
            case 'warn':              showStatus(d.message, 'warn'); break;
            case 'register_progress': updateProgress(d); showStatus(d.message, 'info'); break;
            case 'register_success':
                showStatus(`Profil <strong>${d.name}</strong> berhasil disimpan.`, 'success');
                isScanning = false; lastBbox = null;
                guideBox.classList.remove('active');
                document.getElementById('btn-cancel').classList.add('hidden');
                document.getElementById('btn-start').classList.remove('hidden');
                document.getElementById('progress-area').classList.add('hidden');
                document.getElementById('user-name').value = '';
                ringFill.style.strokeDashoffset = RING_CIRC;
                ringText.textContent = '0%';
                // Session habis — kunci kembali setelah 2 detik
                setTimeout(() => {
                    lockEnroll();
                    showPwSuccess(d.name);
                }, 2000);
                break;
            case 'register_error':
                showStatus(d.message, 'error'); isScanning = false;
                document.getElementById('btn-cancel').classList.add('hidden');
                document.getElementById('btn-start').classList.remove('hidden');
                document.getElementById('progress-area').classList.add('hidden');
                break;
            case 'status': showStatus(d.message, 'info'); break;
            case 'error':
                showStatus(d.message, 'error');
                setChip('<i class="fas fa-times text-[10px]"></i>Error', 'bg-red-500/20', 'text-red-400');
                break;
        }
    };

    ws.onclose = () => {
        stopScanLoop();
        setChip('<i class="fas fa-times text-[10px]"></i>Terputus', 'bg-red-500/20', 'text-red-400');
        showStatus('Koneksi terputus. Mencoba kembali…', 'error');
        reconnectAttempts++;
        reconnectTimer = setTimeout(connect, Math.min(1000 * Math.pow(1.5, reconnectAttempts), MAX_RECONNECT));
    };
    ws.onerror = () => {};
}

document.getElementById('enroll-form').addEventListener('submit', e => {
    e.preventDefault();
    const name = document.getElementById('user-name').value.trim();
    if (!name) { showStatus('Masukkan nama terlebih dahulu.', 'warn'); return; }
    if (!ws || ws.readyState !== WebSocket.OPEN) { showStatus('Belum terhubung. Tunggu sebentar…', 'error'); return; }
    isScanning = true;
    ws.send(JSON.stringify({ type: 'register_start', name }));
    document.getElementById('btn-start').classList.add('hidden');
    document.getElementById('btn-cancel').classList.remove('hidden');
    document.getElementById('progress-area').classList.remove('hidden');
    showStatus(`Memulai scan untuk <strong>${name}</strong>…`, 'info');
});

document.getElementById('btn-cancel').addEventListener('click', () => {
    isScanning = false; lastBbox = null;
    guideBox.classList.remove('active');
    if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify({ type: 'register_cancel' }));
    document.getElementById('btn-start').classList.remove('hidden');
    document.getElementById('btn-cancel').classList.add('hidden');
    document.getElementById('progress-area').classList.add('hidden');
    ringFill.style.strokeDashoffset = RING_CIRC;
    ringText.textContent = '0%';
    showStatus('Pendaftaran dibatalkan.', 'warn');
});

canvas.width = 320; canvas.height = 240;
ctx.fillStyle = '#020617'; ctx.fillRect(0,0,320,240);
ctx.fillStyle = '#334155'; ctx.font = '13px Plus Jakarta Sans, system-ui';
ctx.textAlign = 'center'; ctx.fillText('Menghubungkan ke kamera…', 160, 120);

connect();
</script>

<script>
// ── Password Gate ──────────────────────────────────────────────────────────────
// Password disimpan hashed di session storage — tidak pernah dikirim ke server.
// Nilai hash di bawah = SHA-256 dari password yang dikonfigurasi di .env
const ENROLL_PW_HASH = '{{ hash("sha256", config("app.enroll_password", "homesafe123")) }}';

async function sha256(str) {
    const buf = await crypto.subtle.digest('SHA-256',
        new TextEncoder().encode(str));
    return Array.from(new Uint8Array(buf))
        .map(b => b.toString(16).padStart(2,'0')).join('');
}

async function verifyPassword() {
    const val = document.getElementById('pw-input').value;
    if (!val) return;
    const hash = await sha256(val);
    if (hash === ENROLL_PW_HASH) {
        // Hapus notice sukses sebelumnya jika ada
        const old = document.getElementById('pw-success-notice');
        if (old) old.remove();
        // TIDAK simpan ke sessionStorage — setiap sesi enroll harus input ulang
        unlockEnroll();
    } else {
        const box = document.getElementById('pw-modal-box');
        const err = document.getElementById('pw-error');
        document.getElementById('pw-input').value = '';
        err.classList.remove('hidden');
        box.classList.add('pw-shake');
        box.addEventListener('animationend', () => box.classList.remove('pw-shake'), { once: true });
    }
}

function unlockEnroll() {
    document.getElementById('pw-gate').style.display = 'none';
    document.getElementById('pw-input').value = '';
    document.getElementById('pw-error').classList.add('hidden');
    document.getElementById('enroll-content').classList.remove('hidden');
}

function lockEnroll() {
    // Clear session — next visit requires password again
    sessionStorage.removeItem('enroll_unlocked');
    // Hide content, show modal again
    document.getElementById('enroll-content').classList.add('hidden');
    document.getElementById('pw-gate').style.display = 'flex';
    document.getElementById('pw-input').value = '';
    document.getElementById('pw-error').classList.add('hidden');
    document.getElementById('pw-input').focus();
}

// Show success notice inside the password modal (called after re-lock)
function showPwSuccess(name) {
    // Remove previous notice if any
    const old = document.getElementById('pw-success-notice');
    if (old) old.remove();

    const notice = document.createElement('div');
    notice.id = 'pw-success-notice';
    notice.style.cssText = `
        margin-bottom: 16px;
        padding: 10px 14px;
        border-radius: 10px;
        background: rgba(16,185,129,0.12);
        border: 1px solid rgba(16,185,129,0.25);
        color: #6ee7b7;
        font-size: 0.8rem;
        display: flex; align-items: center; gap: 8px;
    `;
    notice.innerHTML = `
        <i class="fas fa-check-circle flex-shrink-0"></i>
        <span>Profil <strong>${name}</strong> berhasil disimpan.<br>
        <span style="opacity:.75;">Masukkan sandi lagi untuk mendaftarkan anggota baru.</span></span>
    `;

    // Insert before the password input
    const pwInput = document.getElementById('pw-input');
    pwInput.parentElement.parentElement.insertBefore(notice, pwInput.parentElement);
}

document.getElementById('pw-submit').addEventListener('click', verifyPassword);
document.getElementById('pw-input').addEventListener('keydown', e => {
    if (e.key === 'Enter') verifyPassword();
    if (document.getElementById('pw-error')) {
        document.getElementById('pw-error').classList.add('hidden');
    }
});

// Toggle password visibility
document.getElementById('pw-toggle-vis').addEventListener('click', () => {
    const inp  = document.getElementById('pw-input');
    const icon = document.getElementById('pw-eye-icon');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'fas fa-eye';
    }
});
</script>
@endpush
