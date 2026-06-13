@extends('layouts.app')

@section('title', 'Pilih Kamera - Smart Lock')

@section('content')
<div class="max-w-4xl mx-auto">
    <div class="bg-gray-800 rounded-lg p-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-xl font-bold"><i class="fas fa-camera text-blue-400 mr-2"></i>Pilih Kamera</h2>
                <p class="text-gray-400 mt-1">Pilih kamera yang ingin digunakan untuk Face Recognition dan CCTV.</p>
            </div>
            <button type="button" id="btn-refresh" onclick="loadCameras()"
                class="px-3 py-2 bg-gray-700 hover:bg-gray-600 rounded transition">
                <i class="fas fa-sync-alt mr-1"></i>Refresh
            </button>
        </div>

        {{-- Info keamanan --}}
        <div class="bg-yellow-900/50 border border-yellow-700 rounded-lg p-3 mb-6 text-sm text-yellow-300">
            <i class="fas fa-shield-alt mr-1"></i>
            <strong>Izin Kamera:</strong> Browser akan meminta izin kamera. 
            Pastikan Anda mengizinkan akses kamera saat muncul popup.
            <br>
            <i class="fas fa-info-circle mr-1 mt-1 inline-block"></i>
            Buka halaman via <strong>http://127.0.0.1:8000</strong> (bukan file://)
        </div>

        <form id="camera-form" action="{{ route('home') }}" method="GET" class="space-y-6">
            {{-- Kamera 1: Face Recognition --}}
            <div class="bg-gray-700/50 rounded-lg p-4">
                <label class="block font-semibold mb-3 text-lg">
                    <i class="fas fa-door-open text-blue-400 mr-2"></i>
                    Kamera 1 - Face Recognition (Pintu)
                </label>
                <select id="recognition-camera" name="recog" 
                    class="w-full px-4 py-2 rounded bg-gray-700 border border-gray-600 focus:border-blue-500 focus:outline-none text-white">
                    <option value="">-- Pilih Kamera --</option>
                </select>
                <div class="mt-3">
                    <video id="preview-recog" autoplay muted playsinline class="w-full rounded bg-black" style="max-height: 240px; object-fit: contain;"></video>
                </div>
            </div>

            {{-- Kamera 2: CCTV --}}
            <div class="bg-gray-700/50 rounded-lg p-4">
                <label class="block font-semibold mb-3 text-lg">
                    <i class="fas fa-video text-green-400 mr-2"></i>
                    Kamera 2 - CCTV Monitoring
                </label>
                <select id="cctv-camera" name="cctv" 
                    class="w-full px-4 py-2 rounded bg-gray-700 border border-gray-600 focus:border-green-500 focus:outline-none text-white">
                    <option value="">-- Pilih Kamera --</option>
                </select>
                <div class="mt-3">
                    <video id="preview-cctv" autoplay muted playsinline class="w-full rounded bg-black" style="max-height: 240px; object-fit: contain;"></video>
                </div>
            </div>

            <button type="submit" id="btn-start" disabled
                class="w-full py-3 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-600 disabled:cursor-not-allowed rounded-lg font-bold text-lg transition">
                <i class="fas fa-play mr-2"></i>Mulai Monitoring
            </button>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Cek dukungan browser
if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    document.body.innerHTML = `
        <div class="min-h-screen bg-gray-900 flex items-center justify-center p-6">
            <div class="bg-gray-800 rounded-lg p-8 max-w-lg text-center">
                <i class="fas fa-exclamation-triangle text-6xl text-yellow-400 mb-4"></i>
                <h2 class="text-2xl font-bold mb-2">Browser Tidak Mendukung Kamera</h2>
                <p class="text-gray-400 mb-4">Browser Anda tidak mendukung akses kamera. Silakan gunakan Chrome, Firefox, atau Edge terbaru.</p>
                <p class="text-gray-500 text-sm">Pastikan Anda membuka halaman ini via <strong>http://localhost:8000</strong> atau <strong>https://</strong></p>
            </div>
        </div>
    `;
    throw new Error('Browser tidak mendukung kamera');
}

let allDevices = [];
let streams = { recog: null, cctv: null };

const recogSelect = document.getElementById('recognition-camera');
const cctvSelect = document.getElementById('cctv-camera');
const btnStart = document.getElementById('btn-start');

async function loadCameras() {
    try {
        // Minta izin kamera dulu — penting agar enumerateDevices() mengembalikan label
        let tempStream = null;
        try {
            tempStream = await navigator.mediaDevices.getUserMedia({ video: true });
        } catch (permErr) {
            console.warn('Izin kamera ditolak:', permErr);
            recogSelect.innerHTML = '<option value="">⚠️ Izin kamera ditolak — refresh halaman & izinkan</option>';
            cctvSelect.innerHTML = '<option value="">⚠️ Izin kamera ditolak</option>';
            return;
        }
        
        // Stop stream sementara
        if (tempStream) tempStream.getTracks().forEach(t => t.stop());
        
        // Tunggu sebentar agar device labels terisi
        await new Promise(r => setTimeout(r, 500));
        
        const devices = await navigator.mediaDevices.enumerateDevices();
        allDevices = devices.filter(d => d.kind === 'videoinput');
        
        recogSelect.innerHTML = '<option value="">-- Pilih Kamera --</option>';
        cctvSelect.innerHTML = '<option value="">-- Pilih Kamera --</option>';
        
        if (allDevices.length === 0) {
            recogSelect.innerHTML = '<option value="">❌ Tidak ada kamera terdeteksi</option>';
            cctvSelect.innerHTML = '<option value="">❌ Tidak ada kamera terdeteksi</option>';
            btnStart.disabled = true;
            return;
        }
        
        allDevices.forEach((device, index) => {
            const label = device.label || `Kamera ${index + 1}`;
            const opt1 = document.createElement('option');
            opt1.value = device.deviceId;
            opt1.textContent = label;
            recogSelect.appendChild(opt1);
            
            const opt2 = document.createElement('option');
            opt2.value = device.deviceId;
            opt2.textContent = label;
            cctvSelect.appendChild(opt2);
        });

        // Auto-select: kamera pertama untuk recognition
        recogSelect.value = allDevices[0].deviceId;
        startPreview('recog', allDevices[0].deviceId);
        
        // CCTV: jika ada 2+ kamera, pakai kedua. Jika cuma 1, pakai kamera yang sama
        if (allDevices.length >= 2) {
            cctvSelect.value = allDevices[1].deviceId;
        } else {
            cctvSelect.value = allDevices[0].deviceId;
        }
        startPreview('cctv', cctvSelect.value);
        
        checkReady();
    } catch (err) {
        console.error('Camera error:', err);
        recogSelect.innerHTML = '<option value="">❌ Error: ' + err.message + '</option>';
        cctvSelect.innerHTML = '<option value="">❌ Error: ' + err.message + '</option>';
    }
}

async function startPreview(type, deviceId) {
    const videoEl = type === 'recog' ? document.getElementById('preview-recog') : document.getElementById('preview-cctv');
    const selectEl = type === 'recog' ? recogSelect : cctvSelect;
    
    // Stop existing stream
    if (streams[type]) {
        streams[type].getTracks().forEach(t => t.stop());
        streams[type] = null;
    }
    
    if (!deviceId) return;
    
    try {
        const constraints = {
            video: {
                deviceId: { exact: deviceId },
                width: { ideal: 320 },
                height: { ideal: 240 }
            }
        };
        
        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        streams[type] = stream;
        videoEl.srcObject = stream;
    } catch (err) {
        console.error(`Preview error for ${type}:`, err);
        videoEl.srcObject = null;
    }
}

// Event: ganti kamera
recogSelect.addEventListener('change', () => {
    startPreview('recog', recogSelect.value);
    checkReady();
});

cctvSelect.addEventListener('change', () => {
    startPreview('cctv', cctvSelect.value);
    checkReady();
});

function checkReady() {
    if (recogSelect.value && cctvSelect.value) {
        btnStart.disabled = false;
        btnStart.className = 'w-full py-3 bg-blue-600 hover:bg-blue-700 rounded-lg font-bold text-lg transition';
    } else {
        btnStart.disabled = true;
        btnStart.className = 'w-full py-3 bg-gray-600 cursor-not-allowed rounded-lg font-bold text-lg transition';
    }
}

// Form submit - langsung submit form tanpa preventDefault
// Biarkan form submit normal via GET ke ?recog=...&cctv=...
document.getElementById('camera-form').addEventListener('submit', (e) => {
    if (!recogSelect.value || !cctvSelect.value) {
        e.preventDefault();
        alert('Silakan pilih kedua kamera terlebih dahulu!');
        return;
    }
    
    // Stop previews sebelum pindah halaman
    Object.keys(streams).forEach(k => {
        if (streams[k]) streams[k].getTracks().forEach(t => t.stop());
    });
    // Biarkan form submit normal
});

// Load cameras on page load
loadCameras();

// Refresh camera list when devices change
navigator.mediaDevices.addEventListener('devicechange', () => {
    // Stop previews
    Object.keys(streams).forEach(k => {
        if (streams[k]) streams[k].getTracks().forEach(t => t.stop());
    });
    loadCameras();
});
</script>
@endpush