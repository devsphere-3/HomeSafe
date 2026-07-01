# Quick Start - Migrasi Backend ke Raspberry Pi

## 📋 Ringkasan

Proyek ini telah dipersiapkan untuk migrasi backend ke Raspberry Pi. Berikut adalah ringkasan perubahan:

### ✅ Yang Sudah Dilakukan

1. **Backend Organised & Documented**
   - `backend/README.md` - Dokumentasi lengkap backend
   - `backend/setup.sh` - Script otomatis setup Raspberry Pi
   - `backend/smartlock.service` - Systemd service untuk auto-start

2. **Frontend Updated untuk Remote Backend**
   - `app/Http/Controllers/FaceRecognitionController.php` - Sekarang menggunakan `BACKEND_URL` dari `.env`
   - `resources/views/recognition/index.blade.php` - WebSocket URL dinamis
   - `resources/views/recognition/enroll.blade.php` - WebSocket URL dinamis
   - `.env.example` - Template konfigurasi dengan `BACKEND_URL`

3. **Deployment Documentation**
   - `DEPLOYMENT.md` - Panduan deployment lengkap
   - `MIGRATION.md` - Checklist migrasi
   - `.gitignore` - Updated untuk mengecualikan file backend yang tidak perlu di-commit

## 🎯 Langkah Cepat

### 1. Copy Backend ke Raspberry Pi

```bash
# Dari komputer lokal (Windows PowerShell):
scp -r backend pi@raspberrypi.local:/home/pi/smartlock/
```

### 2. Setup di Raspberry Pi

```bash
# SSH ke Raspberry Pi
ssh pi@raspberrypi.local

# Setup backend
cd /home/pi/smartlock
chmod +x setup.sh
./setup.sh
```

### 3. Start Backend

```bash
# Test manual
source venv/bin/activate
python app.py

# Atau install sebagai service (recommended)
sudo cp smartlock.service /etc/systemd/system/
sudo systemctl enable smartlock
sudo systemctl start smartlock
```

### 4. Configure Frontend (Local Machine)

Edit `.env` di komputer lokal:
```env
BACKEND_URL=http://raspberrypi.local:5001
```

### 5. Run Frontend

```bash
# Di komputer lokal
php artisan serve
```

### 6. Test

Buka browser:
```
http://localhost:8000/recognition?recog=<device_id>&cctv=<device_id>
```

## 📁 File Structure

### Di Raspberry Pi (`/home/pi/smartlock/`)
```
backend/
├── app.py
├── recognizer.py
├── download_model.py
├── requirements.txt
├── database.json
├── models/
│   ├── blaze_face_short_range.tflite
│   └── face_recognition_sface_2021dec.onnx
├── setup.sh
├── smartlock.service
└── README.md
```

### Di Local Machine (TIDAK BERUBAH)
```
HomeSafe/
├── app/                    # Laravel backend (PHP)
├── resources/              # Laravel views
├── routes/
├── config/
├── public/
├── .env                    # ← TAMBAHKAN: BACKEND_URL=http://raspberrypi.local:5001
├── composer.json
├── package.json
└── ...
```

## 🔧 Konfigurasi Penting

### `.env` (Local Machine)
```env
# Tambahkan baris ini:
BACKEND_URL=http://raspberrypi.local:5001

# Atau gunakan IP address:
# BACKEND_URL=http://192.168.1.100:5001
```

### `backend/app.py` (Raspberry Pi)
- Host: `0.0.0.0` (menerima koneksi dari semua IP)
- Port: `5001`
- WebSocket timeout: 30 detik

## 📚 Dokumentasi Lengkap

- **DEPLOYMENT.md** - Panduan deployment detail
- **MIGRATION.md** - Checklist migrasi lengkap
- **backend/README.md** - Dokumentasi backend

## ⚠️ Important Notes

1. **Frontend TIDAK dipindah** - Tetap di local machine
2. **Backend HANYA di Raspberry Pi** - Folder `backend/` dipindah penuh
3. **Kamera di Raspberry Pi** - Backend mengakses kamera langsung
4. **Network** - Kedua device harus di jaringan yang sama
5. **Static IP** - Disarankan untuk Raspberry Pi agar URL tidak berubah

## 🆘 Troubleshooting Cepat

### Backend tidak bisa diakses
```bash
# Cek status
sudo systemctl status smartlock

# Test lokal
curl http://localhost:5001/api/users

# Test dari komputer lain
curl http://raspberrypi.local:5001/api/users
```

### Camera error
```bash
# Check camera
ls /dev/video*
sudo usermod -aG video $USER
# Logout dan login kembali
```

### WebSocket connection failed
- Pastikan `BACKEND_URL` di `.env` benar
- Cek firewall: `sudo ufw status`
- Cek logs: `sudo journalctl -u smartlock -f`

## 🎓 Next Steps

1. Baca [DEPLOYMENT.md](DEPLOYMENT.md) untuk panduan lengkap
2. Baca [MIGRATION.md](MIGRATION.md) untuk checklist detail
3. Setup Raspberry Pi sesuai panduan
4. Test semua fitur sebelum production

## 📞 Support

Jika mengalami masalah:
1. Cek logs: `sudo journalctl -u smartlock -f`
2. Baca dokumentasi di `backend/README.md`
3. Baca troubleshooting di `DEPLOYMENT.md`