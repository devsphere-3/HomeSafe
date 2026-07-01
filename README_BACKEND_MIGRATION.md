# Backend Migration to Raspberry Pi - Complete Guide

## 🎯 Objective

Memindahkan seluruh backend (Python/FastAPI) dari local machine ke Raspberry Pi, sementara frontend (Laravel) tetap di local machine.

## ✅ What Has Been Prepared

### 1. Backend Files (Ready to Move)
Semua file backend telah dirapikan dan siap dipindah ke Raspberry Pi:

```
backend/
├── app.py                    # Main FastAPI application
├── recognizer.py             # Face recognition engine
├── download_model.py         # ML model downloader
├── requirements.txt          # Python dependencies
├── database.json             # Face database (akan terisi otomatis)
├── models/                   # ML models (SFace + MediaPipe)
│   ├── blaze_face_short_range.tflite
│   └── face_recognition_sface_2021dec.onnx
├── setup.sh                  # Automated setup script for Raspberry Pi
├── smartlock.service         # Systemd service for auto-start
└── README.md                 # Backend documentation
```

### 2. Frontend Updates (Already Done)
Frontend telah diupdate untuk mendukung backend yang berjalan di remote:

- ✅ `app/Http/Controllers/FaceRecognitionController.php` - Menggunakan `BACKEND_URL` dari `.env`
- ✅ `resources/views/recognition/index.blade.php` - WebSocket URL dinamis
- ✅ `resources/views/recognition/enroll.blade.php` - WebSocket URL dinamis
- ✅ `.env.example` - Template dengan `BACKEND_URL`

### 3. Documentation
- ✅ `DEPLOYMENT.md` - Panduan deployment lengkap
- ✅ `MIGRATION.md` - Checklist migrasi detail
- ✅ `QUICK_START.md` - Panduan cepat
- ✅ `backend/README.md` - Dokumentasi backend

## 🚀 Quick Migration Steps

### Step 1: Copy Backend to Raspberry Pi
```bash
# From Windows PowerShell
scp -r backend pi@raspberrypi.local:/home/pi/smartlock/
```

### Step 2: Setup on Raspberry Pi
```bash
ssh pi@raspberrypi.local
cd /home/pi/smartlock
chmod +x setup.sh
./setup.sh
```

### Step 3: Start Backend Service
```bash
sudo cp smartlock.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable smartlock
sudo systemctl start smartlock
```

### Step 4: Configure Frontend
Edit `.env` on local machine:
```env
BACKEND_URL=http://raspberrypi.local:5001
```

### Step 5: Run Frontend
```bash
php artisan serve
```

## 📋 Checklist Before Migration

- [ ] Raspberry Pi sudah di-flash dengan Raspberry Pi OS
- [ ] Raspberry Pi terhubung ke network yang sama dengan komputer lokal
- [ ] SSH sudah enabled di Raspberry Pi
- [ ] Kamera sudah terhubung ke Raspberry Pi
- [ ] File `backend/` sudah di-copy ke Raspberry Pi
- [ ] Script `setup.sh` sudah dijalankan
- [ ] Backend service sudah di-install dan running
- [ ] `.env` lokal sudah diupdate dengan `BACKEND_URL`
- [ ] Frontend bisa mengakses backend

## 🔧 Configuration

### Backend (Raspberry Pi)
- **Host**: `0.0.0.0` (accepts all connections)
- **Port**: `5001`
- **Protocol**: HTTP + WebSocket
- **Camera**: Direct access via USB/Pi Camera

### Frontend (Local Machine)
- **URL**: `http://localhost:8000`
- **Backend URL**: `http://raspberrypi.local:5001` (configured in `.env`)
- **Camera**: Browser-based camera access

## 📁 File Locations

### After Migration

**Raspberry Pi** (`/home/pi/smartlock/`):
- Backend application
- ML models
- Face database
- Virtual environment
- Systemd service

**Local Machine** (unchanged):
- Laravel application
- Frontend views
- All source code
- `.env` with `BACKEND_URL`

## 🔍 Verification

### Test Backend
```bash
# From Raspberry Pi
curl http://localhost:5001/api/users

# From local machine
curl http://raspberrypi.local:5001/api/users
```

### Test Frontend
```
http://localhost:8000/recognition?recog=<device_id>&cctv=<device_id>
```

### Check Service Status
```bash
sudo systemctl status smartlock
sudo journalctl -u smartlock -f
```

## 📚 Documentation

- **[QUICK_START.md](QUICK_START.md)** - Quick start guide
- **[DEPLOYMENT.md](DEPLOYMENT.md)** - Detailed deployment guide
- **[MIGRATION.md](MIGRATION.md)** - Migration checklist
- **[backend/README.md](backend/README.md)** - Backend documentation

## ⚠️ Important Notes

1. **Frontend stays on local machine** - Only backend moves to Raspberry Pi
2. **Both devices must be on same network** - Required for WebSocket communication
3. **Static IP recommended for Raspberry Pi** - Prevents URL changes
4. **Camera is on Raspberry Pi** - Backend accesses camera directly
5. **Face database is on Raspberry Pi** - `database.json` stays on Pi

## 🆘 Troubleshooting

### Backend not accessible
```bash
# Check service
sudo systemctl status smartlock

# Check firewall
sudo ufw status

# Test locally
curl http://localhost:5001/api/users
```

### Camera not detected
```bash
ls /dev/video*
sudo usermod -aG video $USER
# Logout and login again
```

### WebSocket connection fails
- Verify `BACKEND_URL` in `.env`
- Check firewall allows port 5001
- Check backend logs: `sudo journalctl -u smartlock -f`

## 🎓 Next Steps

1. Read [DEPLOYMENT.md](DEPLOYMENT.md) for detailed instructions
2. Follow [MIGRATION.md](MIGRATION.md) checklist
3. Setup Raspberry Pi
4. Test all features
5. Configure static IP
6. Setup monitoring and backups

## 📞 Support

- Check logs: `sudo journalctl -u smartlock -f`
- Read [DEPLOYMENT.md](DEPLOYMENT.md) troubleshooting section
- Read [backend/README.md](backend/README.md) for backend-specific issues

---

**Status**: ✅ Backend is ready for migration to Raspberry Pi
**Frontend**: ✅ Already configured for remote backend
**Documentation**: ✅ Complete