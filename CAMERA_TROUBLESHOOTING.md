# Camera Troubleshooting Guide for Raspberry Pi

## Masalah: Kamera Sudah Terhubung Tapi Tidak Terdeteksi

### Langkah 1: Test Camera dengan Script

Di Raspberry Pi, jalankan:
```bash
cd /home/pi/smartlock
python3 test_camera.py
```

Script akan:
- Scan kamera 0-9
- Test setiap kamera
- Tampilkan resolusi dan FPS
- Simpan test image

**Output yang diharapkan:**
```
✅ Camera 0: Available
   Resolution: 640x480
   FPS: 30
✅ Successfully read frame from camera 0
   Frame shape: (480, 640, 3)
✅ Test image saved to test_camera_0.jpg
```

Jika tidak ada kamera terdeteksi, lanjut ke langkah 2.

---

### Langkah 2: Check Hardware Connection

#### USB Camera
```bash
# Check USB devices
lsusb

# Output yang diharapkan:
# Bus 001 Device 004: ID 046d:0825 Logitech, Inc. Webcam C270
# atau
# Bus 001 Device 005: ID 0c45:6366 Microdia Camera

# Check kernel messages
dmesg | grep -i camera
dmesg | grep -i video
dmesg | tail -20

# Check video devices
ls -la /dev/video*

# Output yang diharapkan:
# crw-rw----+ 1 root video 81, 0 Nov 23 10:30 /dev/video0
```

#### Raspberry Pi Camera (CSI)
```bash
# Check Pi Camera status
vcgencmd get_camera

# Output yang diharapkan:
# supported=1 detected=1

# Enable camera interface
sudo raspi-config nonint do_camera 0

# Reboot required
sudo reboot
```

---

### Langkah 3: Check Permissions

```bash
# Check user groups
groups

# Output yang diharapkan harus include 'video':
# pi : pi adm sudo video plugdev ...

# Jika tidak ada 'video', add user:
sudo usermod -aG video $USER

# Logout dan login kembali, atau reboot
sudo reboot

# Verify setelah reboot
groups
```

---

### Langkah 4: Install Camera Dependencies

```bash
# Update system
sudo apt update

# Install video utilities
sudo apt install -y v4l-utils
sudo apt install -y fswebcam

# Install OpenCV dependencies
sudo apt install -y libopencv-dev python3-opencv
sudo apt install -y libjpeg-dev libpng-dev libtiff-dev
sudo apt install -y libavcodec-dev libavformat-dev libswscale-dev
sudo apt install -y libgtk-3-dev

# Verify v4l2-ctl
v4l2-ctl --list-devices
```

---

### Langkah 5: Test Camera dengan fswebcam

```bash
# Install fswebcam jika belum
sudo apt install -y fswebcam

# Test capture image
fswebcam -r 640x480 --no-banner test.jpg

# Check if image created
ls -lh test.jpg

# If successful, you should see test.jpg file
```

---

### Langkah 6: Check Camera dengan Python

```bash
# Test dengan Python script sederhana
python3 -c "
import cv2
cap = cv2.VideoCapture(0)
if cap.isOpened():
    ret, frame = cap.read()
    if ret:
        print('Camera works!')
        print(f'Frame shape: {frame.shape}')
    else:
        print('Cannot read frame')
    cap.release()
else:
    print('Cannot open camera')
"
```

---

### Langkah 7: Check OpenCV Installation

```bash
# Check OpenCV version
python3 -c "import cv2; print(cv2.__version__)"

# Should output: 4.8.0 or higher

# Check available backends
python3 -c "
import cv2
print('OpenCV backends:')
print(cv2.videoio_registry.getBackendName(cv2.CAP_V4L2))
"
```

---

### Common Issues & Solutions

#### Issue 1: "Camera not detected" (ls /dev/video* empty)

**Solution:**
```bash
# For USB Camera:
# 1. Unplug and replug camera
# 2. Check USB port (try different port)
# 3. Check dmesg for errors
dmesg | tail -50

# For Pi Camera:
# 1. Enable camera interface
sudo raspi-config nonint do_camera 0
# 2. Reboot
sudo reboot
```

#### Issue 2: "Permission denied" when accessing /dev/video0

**Solution:**
```bash
# Add user to video group
sudo usermod -aG video $USER

# Reboot or logout/login
sudo reboot

# Verify
groups
```

#### Issue 3: Camera opens but cannot read frames

**Solution:**
```bash
# Check if camera is being used by another process
ps aux | grep python
ps aux | grep camera

# Kill any process using camera
sudo killall -9 python3

# Try again
python3 test_camera.py
```

#### Issue 4: "Failed to open camera" in backend

**Solution:**
```bash
# Check if camera is available
ls /dev/video*

# Test with simple script
python3 -c "
import cv2
for i in range(10):
    cap = cv2.VideoCapture(i)
    if cap.isOpened():
        print(f'Camera {i}: Available')
        cap.release()
    else:
        print(f'Camera {i}: Not available')
"

# Update backend/app.py if camera ID is different
# Default is 0, but might be 1 or 2
```

#### Issue 5: Pi Camera shows "supported=1 detected=0"

**Solution:**
```bash
# Camera cable might be loose or damaged
# 1. Power off Raspberry Pi
# 2. Check camera cable connection
# 3. Reconnect camera cable
# 4. Power on and test again

# Enable camera
sudo raspi-config nonint do_camera 0
sudo reboot
```

---

### Verification Steps

Setelah setup, verify dengan:

```bash
# 1. Test camera
python3 test_camera.py

# 2. Check video devices
ls -la /dev/video*

# 3. Check user groups
groups

# 4. Test with fswebcam
fswebcam -r 640x480 --no-banner test.jpg

# 5. Start backend
source venv/bin/activate
python app.py

# 6. In another terminal, test API
curl http://localhost:5001/api/cameras

# Expected output:
# {"cameras":[{"id":0,"name":"Camera 0","resolution":"640x480"}],"current":0}
```

---

### Frontend Testing

Setelah backend running:

1. Buka browser di komputer lokal
2. Akses `http://localhost:8000/recognition`
3. Di bagian "Pilih Sumber Kamera":
   - Dropdown harus menampilkan kamera yang terdeteksi
   - Pilih kamera dari dropdown
   - Klik "Refresh" jika kamera tidak muncul
4. Monitoring harus mulai menampilkan video

---

### Debug Mode

Untuk debugging lebih detail:

```bash
# Run backend dengan verbose logging
cd /home/pi/smartlock
source venv/bin/activate
python app.py

# Check logs di terminal
# Look for messages like:
# - "Camera 0 opened successfully"
# - "Frame capture error: ..."
# - "Available cameras: [{'id': 0, ...}]"
```

---

### Quick Fix Checklist

- [ ] Camera terhubung ke Raspberry Pi
- [ ] Camera enabled di raspi-config
- [ ] User added to video group
- [ ] Reboot setelah perubahan
- [ ] `/dev/video0` exists
- [ ] `python3 test_camera.py` shows camera
- [ ] `v4l2-ctl --list-devices` shows camera
- [ ] Backend running dan accessible
- [ ] Frontend bisa akses `/api/cameras`

---

### Still Not Working?

Jika semua langkah di atas sudah dicoba tapi kamera masih tidak terdeteksi:

1. **Coba kamera lain** - Mungkin kamera yang digunakan tidak kompatibel
2. **Coba port USB lain** - Beberapa port USB di Pi bisa bermasalah
3. **Check Raspberry Pi model** - Beberapa model Pi memiliki keterbatasan
4. **Update Raspberry Pi OS** - `sudo apt update && sudo apt upgrade`
5. **Check power supply** - USB camera butuh power yang cukup (minimal 2.5A)

```bash
# Check power supply
vcgencmd get_throttled

# If throttled, you need better power supply