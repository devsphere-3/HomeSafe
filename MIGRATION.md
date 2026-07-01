# Backend Migration to Raspberry Pi - Checklist

## 📦 What to Move to Raspberry Pi

### Backend Folder (FULL COPY)
```
backend/
├── app.py                    ✓ MOVE
├── recognizer.py             ✓ MOVE
├── download_model.py         ✓ MOVE
├── requirements.txt          ✓ MOVE
├── database.json             ✓ MOVE (atau biarkan kosong, akan terisi otomatis)
├── models/                   ✓ MOVE
│   ├── blaze_face_short_range.tflite
│   └── face_recognition_sface_2021dec.onnx
├── setup.sh                  ✓ MOVE
├── smartlock.service         ✓ MOVE
└── README.md                 ✓ MOVE (untuk referensi)
```

### Files to NOT Move to Raspberry Pi
```
❌ backend/venv/              (akan dibuat otomatis oleh setup.sh)
❌ backend/__pycache__/       (akan dibuat otomatis)
❌ backend/*.log              (log files, akan dibuat saat runtime)
❌ .env                       (tidak dibutuhkan di backend)
❌ .env.example               (tidak dibutuhkan di backend)
```

## 💻 What Stays on Local Machine

### Laravel Frontend (TIDAK PERNAH dipindah)
```
✓ Semua file Laravel tetap di local machine:
  - app/
  - resources/
  - routes/
  - config/
  - public/
  - package.json
  - composer.json
  - .env (lokal)
  - dst...
```

### Configuration Changes Required

#### 1. Update `.env` on Local Machine
```env
# Tambahkan baris ini di .env lokal:
BACKEND_URL=http://raspberrypi.local:5001
# atau
BACKEND_URL=http://192.168.1.100:5001  (gunakan IP Raspberry Pi)
```

#### 2. Files yang Sudah Diupdate untuk Mendukung Remote Backend
- ✅ `app/Http/Controllers/FaceRecognitionController.php` - Sekarang baca `BACKEND_URL` dari `.env`
- ✅ `resources/views/recognition/index.blade.php` - WebSocket URL dinamis
- ✅ `resources/views/recognition/enroll.blade.php` - WebSocket URL dinamis
- ✅ `.env.example` - Dokumentasi konfigurasi

## 🚀 Migration Steps

### Step 1: Prepare Raspberry Pi
```bash
# Di Raspberry Pi
sudo apt update && sudo apt upgrade -y
sudo apt install -y git python3-pip python3-venv
```

### Step 2: Copy Backend Files
```bash
# Opsi A: Via SCP dari Windows
# Di PowerShell/CMD di komputer lokal:
scp -r backend pi@raspberrypi.local:/home/pi/smartlock/

# Opsi B: Via Git (jika sudah push ke GitHub)
# Di Raspberry Pi:
git clone https://github.com/Wahyu-2/HomeSafe.git
cd HomeSafe
mkdir -p /home/pi/smartlock
cp -r backend/* /home/pi/smartlock/
```

### Step 3: Setup di Raspberry Pi
```bash
# Di Raspberry Pi
cd /home/pi/smartlock
chmod +x setup.sh
./setup.sh
```

### Step 4: Test Backend
```bash
# Di Raspberry Pi
source venv/bin/activate
python app.py

# Di tab baru, test dari komputer lokal
curl http://raspberrypi.local:5001/api/users
```

### Step 5: Install Systemd Service
```bash
sudo cp smartlock.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable smartlock
sudo systemctl start smartlock
sudo systemctl status smartlock
```

### Step 6: Configure Frontend
```bash
# Di komputer lokal
# Edit .env, tambahkan:
BACKEND_URL=http://raspberrypi.local:5001

# Restart Laravel
php artisan serve
```

### Step 7: Test Full System
```bash
# Buka browser di komputer lokal
http://localhost:8000/recognition?recog=<device_id_1>&cctv=<device_id_2>

# Dapatkan device ID dari browser:
# - Buka http://localhost:8000/recognition/camera-select
# - Browser akan meminta izin kamera
# - Setelah diizinkan, device ID akan muncul di URL
```

## ✅ Verification Checklist

### Backend (Raspberry Pi)
- [ ] Backend running: `sudo systemctl status smartlock`
- [ ] API accessible: `curl http://raspberrypi.local:5001/api/users`
- [ ] WebSocket running: Cek logs `sudo journalctl -u smartlock -f`
- [ ] Camera detected: `ls /dev/video*`
- [ ] Models loaded: Cek logs untuk "Loaded X users"

### Frontend (Local Machine)
- [ ] `.env` has `BACKEND_URL` configured
- [ ] Laravel running: `php artisan serve`
- [ ] Can access: `http://localhost:8000/recognition?recog=...&cctv=...`
- [ ] WebSocket connects (cek browser console)
- [ ] Face recognition works
- [ ] User enrollment works
- [ ] CCTV monitoring works

### Network
- [ ] Both devices on same network
- [ ] Can ping Raspberry Pi from local machine
- [ ] Port 5001 not blocked by firewall
- [ ] Static IP configured (optional but recommended)

## 🔧 Common Issues & Solutions

### Issue: "Connection refused" di browser
**Solution:**
```bash
# Cek backend running
sudo systemctl status smartlock

# Cek firewall
sudo ufw status

# Test dari Raspberry Pi
curl http://localhost:5001/api/users

# Test dari komputer lokal
curl http://raspberrypi.local:5001/api/users
```

### Issue: "Camera not found"
**Solution:**
```bash
# Check camera
ls /dev/video*
vcgencmd get_camera

# Add user to video group
sudo usermod -aG video $USER
# Logout dan login kembali
```

### Issue: "Model not found"
**Solution:**
```bash
cd /home/pi/smartlock
python download_model.py
```

### Issue: WebSocket keeps disconnecting
**Solution:**
- Cek network stability
- Increase timeout di `app.py` (baris 30: `WS_TIMEOUT = 30`)
- Cek logs: `sudo journalctl -u smartlock -f`

## 📊 Performance Optimization (Raspberry Pi)

### For Raspberry Pi 2GB RAM:
```javascript
// Di resources/views/recognition/index.blade.php
// Ubah resolusi kamera (baris 510-513):
const constraints = {
    video: {
        width: { ideal: 320 },  // Dari 640 ke 320
        height: { ideal: 240 }  // Dari 480 ke 240
    }
};

// Ubah frame interval (baris 549):
setTimeout(capture, 200);  // Dari 150ms ke 200ms
```

### For Raspberry Pi 4GB+ RAM:
- Default settings sudah optimal
- Bisa meningkatkan resolusi ke 640x480 atau 1280x720

## 🔐 Security Notes

1. **Jangan expose port 5001 ke internet**
2. **Gunakan static IP** untuk Raspberry Pi
3. **Ganti password default** Raspberry Pi
4. **Enable SSH key-based auth** (disable password auth)
5. **Regular backups** dari `database.json`

## 📝 Post-Migration

### Setelah migration berhasil:
1. [ ] Backup `database.json` secara berkala
2. [ ] Setup monitoring logs
3. [ ] Test semua fitur (recognition, enrollment, CCTV)
4. [ ] Dokumentasi IP Raspberry Pi di network
5. [ ] Setup automatic updates (jika diinginkan)

## 🆘 Support

Jika mengalami masalah:
1. Cek logs: `sudo journalctl -u smartlock -f`
2. Cek [DEPLOYMENT.md](DEPLOYMENT.md) untuk troubleshooting lengkap
3. Cek [backend/README.md](backend/README.md) untuk dokumentasi backend