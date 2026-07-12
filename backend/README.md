# HomeSafe Backend — Raspberry Pi

Face recognition API untuk sistem smart lock.

## Struktur File

```
backend/
├── app.py              # FastAPI server — WebSocket + REST API
├── recognizer.py       # Engine deteksi & pengenalan wajah
├── download_model.py   # Download model ML otomatis
├── diagnose_camera.py  # Tool diagnosis kamera di RPi
├── requirements.txt    # Python dependencies
├── start.sh            # Script jalankan server
├── homesafe.service    # systemd service (auto-start saat boot)
└── models/             # Dibuat otomatis oleh download_model.py
    ├── blaze_face_short_range.tflite
    └── face_recognition_sface_2021dec.onnx
```

## Setup Pertama Kali

```bash
# 1. Clone / copy folder backend ke RPi
scp -r backend/ pi@<IP_RASPI>:/home/pi/HomeSafe/

# 2. SSH ke RPi
ssh pi@<IP_RASPI>

# 3. Masuk ke folder
cd /home/pi/HomeSafe/backend

# 4. Jalankan (install deps + download model + start server otomatis)
bash start.sh
```

## Auto-start Saat Boot (opsional)

```bash
sudo cp homesafe.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable homesafe
sudo systemctl start homesafe

# Cek status
sudo systemctl status homesafe

# Lihat log live
sudo journalctl -u homesafe -f
```

## Diagnosis Kamera

```bash
python3 diagnose_camera.py
```

## Endpoints

| Method | URL | Keterangan |
|--------|-----|------------|
| WS | `/ws` | Stream pengenalan wajah |
| WS | `/ws/enroll` | Pendaftaran wajah baru |
| WS | `/ws/motion` | Deteksi gerakan CCTV |
| GET | `/api/cameras` | Daftar kamera tersedia |
| POST | `/api/cameras/door/{id}` | Set kamera pintu |
| POST | `/api/cameras/yard/{id}` | Set kamera CCTV |
| GET | `/api/users` | Daftar pengguna terdaftar |
| DELETE | `/api/users/{name}` | Hapus pengguna |
| GET | `/api/history` | Riwayat akses |
| DELETE | `/api/history` | Hapus riwayat |
