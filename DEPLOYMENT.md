# Smart Lock - Deployment Guide

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    Local Machine (Windows/Mac)               │
│  ┌───────────────────────────────────────────────────────┐  │
│  │              Laravel Frontend (FE)                     │  │
│  │  - Port: 8000                                         │  │
│  │  - Camera access via browser                          │  │
│  │  - WebSocket client (connects to Raspberry Pi)        │  │
│  └───────────────────────────────────────────────────────┘  │
│                          │                                   │
│                    HTTP/WebSocket                            │
│                    (Network/LAN)                             │
│                          │                                   │
└──────────────────────────┼───────────────────────────────────┘
                           │
┌──────────────────────────┼───────────────────────────────────┐
│    Raspberry Pi          │                                   │
│  ┌───────────────────────────────────────────────────────┐  │
│  │           FastAPI Backend (BE)                        │  │
│  │  - Port: 5001                                        │  │
│  │  - Face Recognition Engine                           │  │
│  │  - ML Models (SFace, MediaPipe)                      │  │
│  │  - WebSocket Server                                  │  │
│  │  - REST API                                          │  │
│  └───────────────────────────────────────────────────────┘  │
│                          │                                   │
│                    USB Camera / Pi Camera                    │
└─────────────────────────────────────────────────────────────┘
```

## Step-by-Step Deployment

### Phase 1: Prepare Raspberry Pi

#### 1.1 Flash Raspberry Pi OS
```bash
# Download Raspberry Pi Imager dari https://www.raspberrypi.com/software/
# Pilih "Raspberry Pi OS (Bookworm)" dengan recommended
# Enable SSH dan set WiFi credentials di advanced settings
```

#### 1.2 Initial Raspberry Pi Setup
```bash
# SSH ke Raspberry Pi
ssh pi@raspberrypi.local

# Update sistem
sudo apt update && sudo apt upgrade -y

# Install git
sudo apt install -y git

# Clone repository
git clone https://github.com/Wahyu-2/HomeSafe.git
cd HomeSafe
```

#### 1.3 Copy Backend ke Raspberry Pi
```bash
# Dari Raspberry Pi, copy hanya folder backend
# Opsi 1: Manual copy via SCP dari komputer lokal
# Di komputer lokal (Windows):
scp -r backend pi@raspberrypi.local:/home/pi/smartlock/

# Opsi 2: Jika repository sudah di-clone, pindah folder backend
mkdir -p /home/pi/smartlock
cp -r backend/* /home/pi/smartlock/
```

### Phase 2: Setup Backend di Raspberry Pi

#### 2.1 Run Setup Script
```bash
cd /home/pi/smartlock
chmod +x setup.sh
./setup.sh
```

Script ini akan:
- Update sistem packages
- Install dependencies (OpenCV, Python dev tools)
- Enable camera
- Create virtual environment
- Install Python packages
- Download ML models
- Create database.json

#### 2.2 Manual Setup (jika script gagal)
```bash
cd /home/pi/smartlock

# Install system dependencies
sudo apt update
sudo apt install -y python3-pip python3-venv python3-dev
sudo apt install -y libopencv-dev python3-opencv
sudo apt install -y libjpeg-dev libpng-dev libtiff-dev
sudo apt install -y libavcodec-dev libavformat-dev libswscale-dev
sudo apt install -y libgtk-3-dev

# Enable camera
sudo raspi-config nonint do_camera 0
sudo usermod -aG video $USER

# Create venv
python3 -m venv venv
source venv/bin/activate

# Install Python deps
pip install --upgrade pip
pip install -r requirements.txt

# Download models
python download_model.py

# Create database
echo "{}" > database.json
```

#### 2.3 Test Backend
```bash
source venv/bin/activate
python app.py
```

Backend akan berjalan di `http://0.0.0.0:5001`

Test dari komputer lokal:
```bash
# Di komputer lokal
curl http://raspberrypi.local:5001/api/users
```

### Phase 3: Setup Systemd Service (Auto-Start)

#### 3.1 Install Service
```bash
sudo cp smartlock.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable smartlock
sudo systemctl start smartlock
```

#### 3.2 Verify Service
```bash
sudo systemctl status smartlock

# Lihat logs
sudo journalctl -u smartlock -f
```

#### 3.3 Manage Service
```bash
# Stop
sudo systemctl stop smartlock

# Restart
sudo systemctl restart smartlock

# Disable auto-start
sudo systemctl disable smartlock
```

### Phase 4: Setup Frontend (Local Machine)

#### 4.1 Configure Backend URL
```bash
# Di komputer lokal, edit .env
BACKEND_URL=http://raspberrypi.local:5001
# atau gunakan IP address Raspberry Pi
# BACKEND_URL=http://192.168.1.100:5001
```

Cek IP Raspberry Pi:
```bash
# Di Raspberry Pi
hostname -I
```

#### 4.2 Run Laravel
```bash
# Di komputer lokal
composer install
php artisan serve
```

Akses di browser:
```
http://localhost:8000/recognition?recog=<device_id>&cctv=<device_id>
```

### Phase 5: Network Configuration

#### 5.1 Pastikan Kedua Device di Jaringan yang Sama
```bash
# Di Raspberry Pi - cek IP
ip addr show

# Di komputer lokal - cek IP
ipconfig  # Windows
ifconfig  # Mac/Linux
```

#### 5.2 Firewall (jika diperlukan)
```bash
# Di Raspberry Pi - allow port 5001
sudo ufw allow 5001/tcp

# Atau disable firewall untuk testing
sudo ufw disable
```

#### 5.3 Static IP untuk Raspberry Pi (Recommended)
```bash
# Edit dhcpcd.conf
sudo nano /etc/dhcpcd.conf

# Tambahkan di akhir file (sesuaikan dengan network Anda)
interface eth0
static ip_address=192.168.1.100/24
static routers=192.168.1.1
static domain_name_servers=8.8.8.8 8.8.4.4

# Untuk WiFi
interface wlan0
static ip_address=192.168.1.100/24
static routers=192.168.1.1
static domain_name_servers=8.8.8.8 8.8.4.4

# Restart networking
sudo systemctl restart dhcpcd
```

## Troubleshooting

### Backend tidak bisa diakses dari komputer lokal
```bash
# 1. Cek apakah backend running
sudo systemctl status smartlock

# 2. Cek apakah port 5001 listening
sudo netstat -tlnp | grep 5001

# 3. Test dari Raspberry Pi itself
curl http://localhost:5001/api/users

# 4. Test dari komputer lokal
curl http://raspberrypi.local:5001/api/users

# 5. Cek firewall
sudo ufw status
```

### Camera tidak terdeteksi
```bash
# List video devices
ls /dev/video*

# Check camera support
vcgencmd get_camera

# Test camera
sudo apt install -y fswebcam
fswebcam -r 640x480 --no-banner test.jpg

# Add user to video group (jika belum)
sudo usermod -aG video $USER
# Logout dan login kembali
```

### Model tidak ditemukan
```bash
cd /home/pi/smartlock
ls -lh models/

# Jika kosong, download ulang
python download_model.py
```

### WebSocket connection gagal
```bash
# Cek logs backend
sudo journalctl -u smartlock -f

# Pastikan CORS di app.py mengizinkan origin frontend
# Edit backend/app.py baris 18-25
```

### Performance issues di Raspberry Pi
```bash
# Cek resource usage
htop

# Untuk Raspberry Pi 2GB, consider:
# 1. Reduce camera resolution di frontend (ubah 640x480 ke 320x240)
# 2. Increase frame interval (ubah 150ms ke 200ms)
# 3. Use lighter model (jika tersedia)
```

## File Structure di Raspberry Pi

```
/home/pi/smartlock/
├── backend/
│   ├── app.py                 # Main FastAPI app
│   ├── recognizer.py          # Face recognition engine
│   ├── download_model.py      # Model downloader
│   ├── requirements.txt       # Python dependencies
│   ├── database.json          # Face database (auto-generated)
│   ├── models/                # ML models
│   │   ├── face_recognition_sface_2021dec.onnx
│   │   └── blaze_face_short_range.tflite
│   ├── venv/                  # Virtual environment
│   ├── setup.sh               # Setup script
│   ├── smartlock.service      # Systemd service file
│   └── README.md              # Backend documentation
│
└── (frontend ada di komputer lokal, bukan di sini)
```

## Security Considerations

1. **Jangan expose backend ke internet langsung** - Gunakan VPN atau SSH tunnel jika perlu akses dari luar
2. **Ganti password default Raspberry Pi**
3. **Disable SSH password auth** - Gunakan key-based authentication
4. **Firewall** - Hanya buka port yang diperlukan
5. **Regular updates** - `sudo apt update && sudo apt upgrade`

## Maintenance

### Update Backend
```bash
cd /home/pi/smartlock
git pull  # Jika menggunakan git
sudo systemctl restart smartlock
```

### Backup Database
```bash
# Backup face database
cp /home/pi/smartlock/database.json /backup/database_$(date +%Y%m%d).json
```

### Monitor Logs
```bash
# Real-time logs
sudo journalctl -u smartlock -f

# Last 100 lines
sudo journalctl -u smartlock -n 100
```

## Quick Reference

| Task | Command |
|------|---------|
| Start backend | `sudo systemctl start smartlock` |
| Stop backend | `sudo systemctl stop smartlock` |
| Restart backend | `sudo systemctl restart smartlock` |
| Check status | `sudo systemctl status smartlock` |
| View logs | `sudo journalctl -u smartlock -f` |
| Enable auto-start | `sudo systemctl enable smartlock` |
| Disable auto-start | `sudo systemctl disable smartlock` |
| Test API | `curl http://localhost:5001/api/users` |