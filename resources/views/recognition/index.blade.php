@extends('layouts.app')

@section('title', 'Smart Lock - Face Recognition')

@push('styles')
<style>
    #video-feed, #cctv-feed {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 0.5rem;
    }
    #overlay-canvas {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
    }
    .camera-container {
        position: relative;
        background: #000;
        border-radius: 0.5rem;
        overflow: hidden;
        aspect-ratio: 4/3;
    }
    .status-badge {
        position: absolute;
        top: 10px;
        left: 10px;
        z-index: 10;
    }
    .anomaly-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        z-index: 10;
    }
    .lock-indicator {
        position: absolute;
        bottom: 10px;
        left: 10px;
        right: 10px;
        z-index: 10;
        text-align: center;
    }
    #unlock-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        z-index: 20;
        pointer-events: none;
        opacity: 0;
        transition: opacity 0.3s ease;
        border-radius: 0.5rem;
    }
    #unlock-overlay.show {
        opacity: 1;
    }
    #unlock-overlay .check-icon {
        font-size: 80px;
        color: #22c55e;
        text-shadow: 0 0 30px rgba(34, 197, 94, 0.7);
    }
    #unlock-overlay .unlock-text {
        font-size: 24px;
        font-weight: bold;
        color: #22c55e;
        margin-top: 10px;
        text-shadow: 0 0 20px rgba(34, 197, 94, 0.5);
    }
    #unlock-overlay .unlock-name {
        font-size: 18px;
        color: #86efac;
        margin-top: 5px;
    }
    #fps-counter {
        font-weight: 600;
        transition: color 0.3s;
    }
    #fps-counter.fps-low { color: #ef4444; }
    #fps-counter.fps-mid { color: #f59e0b; }
    #fps-counter.fps-high { color: #22c55e; }
    .info-bar {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
    }
    .info-bar span {
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    #cctv-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
    }
    .anomaly-indicator {
        position: absolute;
        top: 40px;
        right: 10px;
        z-index: 10;
        transition: all 0.3s;
    }
    .anomaly-log {
        max-height: 80px;
        overflow-y: auto;
        font-size: 11px;
        font-family: monospace;
        background: rgba(0,0,0,0.3);
        border-radius: 4px;
        padding: 4px 8px;
        margin-top: 4px;
    }
    .anomaly-log div {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .anomaly-log .anomaly-warn { color: #f59e0b; }
    .anomaly-log .anomaly-danger { color: #ef4444; }
    .anomaly-log .anomaly-info { color: #3b82f6; }
    .motion-bar {
        height: 4px;
        border-radius: 2px;
        background: #374151;
        margin-top: 4px;
        overflow: hidden;
    }
    .motion-bar-fill {
        height: 100%;
        border-radius: 2px;
        transition: width 0.3s, background 0.3s;
        width: 0%;
    }
    .motion-bar-fill.low { background: #22c55e; }
    .motion-bar-fill.medium { background: #f59e0b; }
    .motion-bar-fill.high { background: #ef4444; }
</style>
@endpush

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    {{-- Kamera 1: Face Recognition / Pintu --}}
    <div class="bg-gray-800 rounded-lg p-4">
        <h2 class="text-lg font-semibold mb-3 flex items-center">
            <i class="fas fa-door-open text-blue-400 mr-2"></i>
            Pintu Utama - Face Recognition
        </h2>
        <div class="camera-container">
            <video id="video-feed" autoplay muted playsinline></video>
            <canvas id="overlay-canvas"></canvas>
            <div class="status-badge">
                <span id="recognition-status" class="px-2 py-1 text-xs rounded bg-yellow-600">
                    <i class="fas fa-circle-notch fa-spin mr-1"></i>Waiting...
                </span>
            </div>
            <div id="unlock-overlay">
                <div class="check-icon"><i class="fas fa-check-circle"></i></div>
                <div class="unlock-text">ACCESS GRANTED</div>
                <div class="unlock-name" id="unlock-name-display"></div>
            </div>
            <div class="lock-indicator">
                <div id="lock-status" class="inline-flex items-center px-4 py-2 rounded-lg bg-gray-900/80 backdrop-blur-sm">
                    <i id="lock-icon" class="fas fa-lock text-2xl text-red-400 mr-2"></i>
                    <span id="lock-text" class="font-semibold">LOCKED</span>
                    <span id="user-info" class="ml-3 text-sm text-gray-300 hidden"></span>
                </div>
            </div>
        </div>
        <div class="mt-3 info-bar text-sm text-gray-400">
            <span><i class="fas fa-users mr-1"></i>Face count: <span id="face-count">0</span></span>
            <span><i class="fas fa-tachometer-alt mr-1"></i><span id="process-time">0</span>ms</span>
            <span><i class="fas fa-chart-line mr-1"></i><span id="fps-counter">0</span> FPS</span>
            <span id="user-indicator-wrapper" style="display:none;">
                <i class="fas fa-user-check text-green-400 mr-1"></i>
                <span id="user-indicator" class="text-green-400 font-semibold"></span>
            </span>
        </div>
    </div>

    {{-- Kamera 2: CCTV Monitoring + Anomaly Detection --}}
    <div class="bg-gray-800 rounded-lg p-4">
        <h2 class="text-lg font-semibold mb-3 flex items-center">
            <i class="fas fa-video text-green-400 mr-2"></i>
            CCTV Monitoring
            <span class="ml-auto text-xs font-normal text-gray-500" id="anomaly-count-badge">0 anomalies</span>
        </h2>
        <div class="camera-container">
            <video id="cctv-feed" autoplay muted playsinline></video>
            <canvas id="cctv-overlay" style="position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;"></canvas>
            <div class="status-badge">
                <span id="cctv-status" class="px-2 py-1 text-xs rounded bg-green-600">
                    <i class="fas fa-circle mr-1"></i>LIVE
                </span>
            </div>
            <div class="anomaly-indicator">
                <span id="anomaly-status" class="px-2 py-1 text-xs rounded bg-green-600 hidden">
                    <i class="fas fa-shield-alt mr-1"></i>Normal
                </span>
            </div>
        </div>
        <div class="mt-2">
            <div class="flex items-center justify-between text-xs text-gray-400">
                <span><i class="fas fa-running mr-1"></i>Motion: <span id="motion-level">0</span>%</span>
                <span><i class="fas fa-sun mr-1"></i>Brightness: <span id="brightness-level">0</span></span>
                <span><i class="fas fa-clock mr-1"></i><span id="cctv-time">-</span></span>
            </div>
            <div class="motion-bar">
                <div class="motion-bar-fill low" id="motion-bar-fill" style="width:0%"></div>
            </div>
            <div class="anomaly-log" id="anomaly-log">
                <div class="anomaly-info">System initialized. Monitoring...</div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// === WEBSOCKET CONNECTION ===
const WS_URL = 'ws://127.0.0.1:5001/ws';
const video     = document.getElementById('video-feed');
const cctvVideo = document.getElementById('cctv-feed');
const canvas    = document.getElementById('overlay-canvas');
const ctx       = canvas.getContext('2d');
const cctvCanvas = document.getElementById('cctv-overlay');
const cctvCtx    = cctvCanvas.getContext('2d');

let ws = null;
let reconnectTimer = null;
let reconnectAttempts = 0;
const MAX_RECONNECT_DELAY = 15000;

// === FPS COUNTER ===
let fpsTimestamps = [];
let currentFps = 0;

function updateFps() {
    const now = performance.now();
    fpsTimestamps.push(now);
    fpsTimestamps = fpsTimestamps.filter(t => now - t < 3000);
    if (fpsTimestamps.length > 1) {
        currentFps = Math.round((fpsTimestamps.length - 1) / ((now - fpsTimestamps[0]) / 1000));
    }
    const fpsEl = document.getElementById('fps-counter');
    if (fpsEl) {
        fpsEl.textContent = currentFps;
        fpsEl.className = currentFps < 5 ? 'fps-low' : currentFps < 15 ? 'fps-mid' : 'fps-high';
    }
}

// ========================
// CCTV ANOMALY DETECTION
// ========================
let cctvInitialized = false;
let prevCctvFrame = null;
let anomalyCount = 0;
const ANOMALY_LOG_MAX = 50;
const MOTION_THRESHOLD = 25;    // persentase perubahan piksel = motion
const BRIGHTNESS_DROP_THRESHOLD = 15; // brightness turun dibawah ini = kemungkinan tertutup
const DARK_FRAME_WARN_COUNT = 5; // setelah 5 frame gelap berturut-turut = alert

let darkFrameCounter = 0;
let lastAnomalyTime = 0;
let anomalyCooldown = 3000; // ms, minimal jeda antar alert

function addAnomalyLog(message, type = 'info') {
    const log = document.getElementById('anomaly-log');
    const time = new Date().toLocaleTimeString();
    const div = document.createElement('div');
    div.className = `anomaly-${type}`;
    div.textContent = `[${time}] ${message}`;
    log.appendChild(div);
    if (log.children.length > ANOMALY_LOG_MAX) {
        log.removeChild(log.firstChild);
    }
    log.scrollTop = log.scrollHeight;
}

function triggerAnomaly(message, type = 'warn') {
    const now = Date.now();
    if (now - lastAnomalyTime < anomalyCooldown) return;
    lastAnomalyTime = now;
    
    anomalyCount++;
    document.getElementById('anomaly-count-badge').textContent = `${anomalyCount} anomalies`;
    
    // Show anomaly badge
    const badge = document.getElementById('anomaly-status');
    badge.className = `px-2 py-1 text-xs rounded ${type === 'danger' ? 'bg-red-600' : 'bg-orange-600'}`;
    badge.innerHTML = `<i class="fas fa-exclamation-triangle mr-1"></i>${type === 'danger' ? 'ALERT' : 'WARNING'}`;
    badge.classList.remove('hidden');
    
    addAnomalyLog(message, type);
    
    // Draw flash on CCTV overlay
    cctvCtx.fillStyle = type === 'danger' ? 'rgba(239,68,68,0.15)' : 'rgba(245,158,11,0.15)';
    cctvCtx.fillRect(0, 0, cctvCanvas.width, cctvCanvas.height);
    
    // Draw border
    cctvCtx.strokeStyle = type === 'danger' ? '#ef4444' : '#f59e0b';
    cctvCtx.lineWidth = 4;
    cctvCtx.strokeRect(2, 2, cctvCanvas.width - 4, cctvCanvas.height - 4);
    
    // Auto-hide badge after 5 seconds
    setTimeout(() => {
        badge.classList.add('hidden');
        cctvCtx.clearRect(0, 0, cctvCanvas.width, cctvCanvas.height);
    }, 5000);
}

function analyzeCctvFrame(currentFrame, width, height) {
    if (!prevCctvFrame) {
        prevCctvFrame = currentFrame;
        return;
    }
    
    // Hitung brightness rata-rata
    let totalBrightness = 0;
    let changedPixels = 0;
    const totalPixels = width * height;
    const sampleStep = 4; // sample every 4th pixel for performance
    
    for (let y = 0; y < height; y += sampleStep) {
        for (let x = 0; x < width; x += sampleStep) {
            const idx = (y * width + x) * 4;
            if (idx + 3 >= currentFrame.length) continue;
            
            // Brightness current frame
            const r = currentFrame[idx];
            const g = currentFrame[idx + 1];
            const b = currentFrame[idx + 2];
            const brightness = 0.299 * r + 0.587 * g + 0.114 * b;
            totalBrightness += brightness;
            
            // Motion detection - compare with previous frame
            if (prevCctvFrame) {
                const pr = prevCctvFrame[idx];
                const pg = prevCctvFrame[idx + 1];
                const pb = prevCctvFrame[idx + 2];
                const diff = Math.abs(r - pr) + Math.abs(g - pg) + Math.abs(b - pb);
                if (diff > 60) { // sensitivity threshold
                    changedPixels++;
                }
            }
        }
    }
    
    const sampledPixels = Math.ceil(totalPixels / (sampleStep * sampleStep));
    const avgBrightness = totalBrightness / sampledPixels;
    const motionPercent = Math.min(100, Math.round((changedPixels / sampledPixels) * 100));
    
    // Update UI
    document.getElementById('motion-level').textContent = motionPercent;
    document.getElementById('brightness-level').textContent = Math.round(avgBrightness);
    document.getElementById('cctv-time').textContent = new Date().toLocaleTimeString();
    
    // Motion bar
    const bar = document.getElementById('motion-bar-fill');
    bar.style.width = motionPercent + '%';
    bar.className = 'motion-bar-fill ' + (motionPercent > 50 ? 'high' : motionPercent > 20 ? 'medium' : 'low');
    
    // === ANOMALY CHECKS ===
    
    // 1. Dark frame detection (kamera ditutup / diblokir)
    if (avgBrightness < BRIGHTNESS_DROP_THRESHOLD) {
        darkFrameCounter++;
        if (darkFrameCounter >= DARK_FRAME_WARN_COUNT) {
            triggerAnomaly('⚠️ Camera obstructed! Frame is too dark.', 'danger');
            darkFrameCounter = 0;
        }
    } else {
        darkFrameCounter = 0;
    }
    
    // 2. High motion detection (gerakan mencurigakan)
    if (motionPercent > 70) {
        triggerAnomaly(`🚨 High motion detected! (${motionPercent}%)`, 'danger');
    } else if (motionPercent > 50) {
        triggerAnomaly(`⚠️ Significant motion (${motionPercent}%)`, 'warn');
    }
    
    // 3. Extreme brightness change (kemungkinan lampu mati/nyala mendadak)
    // Disimpan untuk digunakan di frame berikutnya
    if (prevCctvFrame) {
        // Hitung brightness change
        let prevBrightness = 0;
        let prevSamples = 0;
        for (let y = 0; y < height; y += sampleStep) {
            for (let x = 0; x < width; x += sampleStep) {
                const idx = (y * width + x) * 4;
                if (idx + 3 >= prevCctvFrame.length) continue;
                const pr = prevCctvFrame[idx];
                const pg = prevCctvFrame[idx + 1];
                const pb = prevCctvFrame[idx + 2];
                prevBrightness += 0.299 * pr + 0.587 * pg + 0.114 * pb;
                prevSamples++;
            }
        }
        if (prevSamples > 0) {
            const avgPrevBrightness = prevBrightness / prevSamples;
            const brightnessDiff = Math.abs(avgBrightness - avgPrevBrightness);
            if (brightnessDiff > 80) {
                triggerAnomaly(`💡 Sudden brightness change! (${Math.round(brightnessDiff)})`, 'warn');
            }
        }
    }
    
    // Simpan frame untuk perbandingan berikutnya
    prevCctvFrame = currentFrame;
}

function processCctvFrame() {
    if (!cctvVideo.videoWidth || !cctvVideo.videoHeight) return;
    
    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = cctvVideo.videoWidth;
    tempCanvas.height = cctvVideo.videoHeight;
    const tempCtx = tempCanvas.getContext('2d');
    tempCtx.drawImage(cctvVideo, 0, 0);
    
    const imageData = tempCtx.getImageData(0, 0, tempCanvas.width, tempCanvas.height);
    analyzeCctvFrame(imageData.data, tempCanvas.width, tempCanvas.height);
}

// ========================
// END CCTV ANOMALY DETECTION
// ========================

function connectWebSocket() {
    if (reconnectTimer) {
        clearTimeout(reconnectTimer);
        reconnectTimer = null;
    }

    console.log('🔌 Connecting WebSocket to', WS_URL);
    ws = new WebSocket(WS_URL);
    ws.binaryType = 'arraybuffer';

    ws.onopen = () => {
        console.log('✅ WebSocket Connected');
        reconnectAttempts = 0;
        document.getElementById('recognition-status').className = 'px-2 py-1 text-xs rounded bg-green-600';
        document.getElementById('recognition-status').innerHTML = '<i class="fas fa-circle mr-1"></i>Connected';
        startCamera();
        startCCTV();
    };

    ws.onmessage = (event) => {
        const data = JSON.parse(event.data);

        if (data.type === 'ping') {
            ws.send(JSON.stringify({ type: 'pong' }));
            return;
        }

        if (data.type === 'result') {
            console.log('📊 Data:', JSON.stringify({
                face: data.face_detected,
                matched: data.matched,
                unlocked: data.unlocked,
                name: data.name,
                pct: data.percentage,
                bbox: !!data.bbox,
                quality: data.quality_issue,
                ms: data.process_time_ms,
                cnt: data.face_count
            }));
            updateRecognitionUI(data);
        }
    };

    ws.onclose = (event) => {
        console.log('❌ WS Disconnected, code:', event.code);
        document.getElementById('recognition-status').className = 'px-2 py-1 text-xs rounded bg-red-600';
        document.getElementById('recognition-status').innerHTML = '<i class="fas fa-times mr-1"></i>Disconnected';

        reconnectAttempts++;
        const delay = Math.min(1000 * Math.pow(1.5, reconnectAttempts), MAX_RECONNECT_DELAY);
        reconnectTimer = setTimeout(connectWebSocket, delay);
    };

    ws.onerror = (err) => {
        console.error('⚠️ WS Error:', err);
    };
}

const urlParams = new URLSearchParams(window.location.search);
const RECOG_DEVICE = urlParams.get('recog');
const CCTV_DEVICE = urlParams.get('cctv');

// === KAMERA 1: FACE RECOGNITION ===
async function startCamera() {
    try {
        const constraints = {
            video: {
                width: { ideal: 640 },
                height: { ideal: 480 }
            }
        };
        
        if (RECOG_DEVICE) {
            constraints.video.deviceId = { exact: RECOG_DEVICE };
        }
        
        console.log('📷 Starting recog camera...');
        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        video.srcObject = stream;

        video.addEventListener('loadedmetadata', () => {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            console.log('📷 Camera ready:', video.videoWidth, 'x', video.videoHeight, 'Canvas:', canvas.width, 'x', canvas.height);
            sendFrames();
        });
    } catch (err) {
        console.error('❌ Camera error:', err);
        document.getElementById('recognition-status').className = 'px-2 py-1 text-xs rounded bg-red-600';
        document.getElementById('recognition-status').innerHTML = '<i class="fas fa-exclamation-triangle mr-1"></i>Camera Error';
    }
}

function sendFrames() {
    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = video.videoWidth;
    tempCanvas.height = video.videoHeight;
    const tempCtx = tempCanvas.getContext('2d');

    function capture() {
        if (ws && ws.readyState === WebSocket.OPEN) {
            tempCtx.drawImage(video, 0, 0);
            const base64 = tempCanvas.toDataURL('image/jpeg', 0.7);
            ws.send(JSON.stringify({ type: 'frame', image: base64 }));
        }
        setTimeout(capture, 150);
    }
    capture();
}

function updateRecognitionUI(data) {
    updateFps();
    
    document.getElementById('face-count').textContent = data.face_count || 0;
    document.getElementById('process-time').textContent = data.process_time_ms || 0;

    // Draw bounding box
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
    if (data.bbox && data.face_detected) {
        const scaleX = canvas.width / (video.videoWidth || 1);
        const scaleY = canvas.height / (video.videoHeight || 1);
        
        const x = (data.bbox.xmin || data.bbox.x || 0) * scaleX;
        const y = (data.bbox.ymin || data.bbox.y || 0) * scaleY;
        const w = (data.bbox.width || 0) * scaleX;
        const h = (data.bbox.height || 0) * scaleY;
        
        const color = data.matched ? '#22c55e' : '#ef4444';
        
        ctx.strokeStyle = color;
        ctx.lineWidth = 3;
        ctx.shadowColor = color;
        ctx.shadowBlur = 12;
        ctx.strokeRect(x, y, w, h);
        ctx.shadowBlur = 0;

        const label = data.matched ? `${data.name} (${data.percentage}%)` : 'Unknown';
        ctx.font = 'bold 14px sans-serif';
        const textW = ctx.measureText(label).width;
        ctx.fillStyle = 'rgba(0,0,0,0.6)';
        ctx.fillRect(x, y - 26, textW + 12, 24);
        ctx.fillStyle = color;
        ctx.fillText(label, x + 6, y - 10);

        const indWrap = document.getElementById('user-indicator-wrapper');
        const ind = document.getElementById('user-indicator');
        if (data.matched) {
            indWrap.style.display = 'inline';
            ind.textContent = `${data.name} (${data.percentage}%)`;
        } else {
            indWrap.style.display = 'none';
        }
    }

    // Lock status
    const lockIcon = document.getElementById('lock-icon');
    const lockText = document.getElementById('lock-text');
    const userInfo = document.getElementById('user-info');
    const lockStatus = document.getElementById('lock-status');
    const unlockOverlay = document.getElementById('unlock-overlay');
    const unlockNameDisplay = document.getElementById('unlock-name-display');

    if (data.unlocked) {
        lockIcon.className = 'fas fa-lock-open text-2xl text-green-400 mr-2';
        lockText.textContent = 'UNLOCKED';
        lockStatus.className = 'inline-flex items-center px-4 py-2 rounded-lg bg-green-900/80 backdrop-blur-sm';
        userInfo.textContent = `${data.name} (${data.percentage}%)`;
        userInfo.className = 'ml-3 text-sm text-green-300';
        userInfo.classList.remove('hidden');

        unlockNameDisplay.textContent = data.name;
        unlockOverlay.classList.add('show');

        setTimeout(() => {
            unlockOverlay.classList.remove('show');
        }, 2000);
    } else {
        lockIcon.className = 'fas fa-lock text-2xl text-red-400 mr-2';
        lockText.textContent = 'LOCKED';
        lockStatus.className = 'inline-flex items-center px-4 py-2 rounded-lg bg-red-900/80 backdrop-blur-sm';
        userInfo.classList.add('hidden');
        unlockOverlay.classList.remove('show');
    }

    // Status badge
    const statusBadge = document.getElementById('recognition-status');
    if (data.quality_issue) {
        statusBadge.className = 'px-2 py-1 text-xs rounded bg-orange-600';
        statusBadge.innerHTML = `<i class="fas fa-exclamation-circle mr-1"></i>${data.quality_issue.replace('_', ' ')}`;
    } else if (data.face_detected) {
        statusBadge.className = 'px-2 py-1 text-xs rounded bg-blue-600';
        statusBadge.innerHTML = '<i class="fas fa-face-smile mr-1"></i>Face detected';
    } else {
        statusBadge.className = 'px-2 py-1 text-xs rounded bg-yellow-600';
        statusBadge.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-1"></i>No face';
    }
}

// === KAMERA 2: CCTV MONITORING + ANOMALY DETECTION ===
async function startCCTV() {
    try {
        const constraints = {
            video: {
                width: { ideal: 640 },
                height: { ideal: 480 }
            }
        };
        
        if (CCTV_DEVICE) {
            constraints.video.deviceId = { exact: CCTV_DEVICE };
        }
        
        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        cctvVideo.srcObject = stream;

        cctvVideo.addEventListener('loadedmetadata', () => {
            document.getElementById('cctv-status').className = 'px-2 py-1 text-xs rounded bg-green-600';
            document.getElementById('cctv-status').innerHTML = '<i class="fas fa-circle mr-1"></i>LIVE';
            
            // Init CCTV canvas
            cctvCanvas.width = cctvVideo.videoWidth;
            cctvCanvas.height = cctvVideo.videoHeight;
            
            // Start CCTV analysis loop
            setInterval(processCctvFrame, 500); // analyze every 500ms
        });
    } catch (err) {
        console.error('CCTV error:', err);
        document.getElementById('cctv-status').className = 'px-2 py-1 text-xs rounded bg-red-600';
        document.getElementById('cctv-status').innerHTML = '<i class="fas fa-exclamation-triangle mr-1"></i>CCTV Unavailable';
    }
}

// Start connection
console.log('🚀 Initializing...');
connectWebSocket();
</script>
@endpush