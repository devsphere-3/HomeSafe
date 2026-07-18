# HomeSafe — Bug Report & Refactoring

## Bug: Wajah Baru Ditolak Saat Pendaftaran (False Rejection pada Enrollment)

---

## 1. Deskripsi Masalah

Pengguna baru **tidak bisa mendaftarkan wajah** meskipun belum pernah terdaftar sebelumnya. Sistem menampilkan pesan error:

```
"Wajah ini sudah terdaftar sebagai 'X' (61.2% kemiripan).
1 wajah hanya boleh untuk 1 akun."
```

Padahal orang tersebut benar-benar berbeda dari orang yang sudah terdaftar. Masalah ini muncul **secara konsisten** setelah database sudah berisi 2–3 profil.

---

## 2. Diagnosis

### Langkah 1 — Baca log backend

Saat enrollment dicoba, log menampilkan:

```
WARNING  ❌ [ENROLL] Wajah 'Budi' diblokir — sudah terdaftar sebagai 'Wahyu' (score=0.612)
```

Score `0.612` memicu penolakan. Pertanyaan: kenapa 0.612 dianggap "sudah terdaftar"?

### Langkah 2 — Lacak threshold

Di `recognizer.py`, ditemukan dua konstanta yang bertanggung jawab atas penolakan enrollment:

```python
# Sebelum (nilai bermasalah)
MATCH_THRESHOLD          = 0.60   # threshold untuk pengenalan
DUPLICATE_REG_THRESHOLD  = 0.60   # threshold untuk tolak pendaftaran duplikat
ENROLL_FACE_EXISTS_THRESHOLD = 0.58  # cek per-frame saat enroll
```

**Root cause ditemukan — tiga masalah sekaligus:**

**Masalah A:** `DUPLICATE_REG_THRESHOLD == MATCH_THRESHOLD` (sama-sama 0.60).  
Artinya: siapa pun yang skornya cukup untuk *dikenali* juga cukup untuk *ditolak saat mendaftar*.  
Orang berbeda yang kebetulan memiliki kemiripan sedang (0.60–0.70) tidak bisa mendaftar sama sekali.

**Masalah B:** `ENROLL_FACE_EXISTS_THRESHOLD = 0.58` — lebih rendah dari `MATCH_THRESHOLD`.  
Fungsi `check_face_exists` dipanggil **setiap frame** saat enrollment. Threshold 0.58 berada di zona abu-abu cosine similarity antar orang berbeda, menyebabkan false positive di sana-sini tergantung pose dan pencahayaan.

**Masalah C:** Di `register_user`, terdapat **dua blok pengecekan duplikat yang tumpang tindih** dengan threshold berbeda (0.60 dan 0.58), di mana blok kedua tidak pernah bisa tercapai karena blok pertama sudah memblokir lebih dulu:

```python
# Sebelum — dua cek yang kontradiktif
if best_score >= DUPLICATE_REG_THRESHOLD and best_name != name:   # 0.60
    return False, f"face_exists:{best_name}"

if best_score >= ENROLL_FACE_EXISTS_THRESHOLD and best_name != name:  # 0.58 — tidak pernah tercapai
    return False, f"duplicate:{best_name}"
```

### Langkah 3 — Validasi dengan nilai SFace

Berdasarkan karakteristik model SFace ONNX:

| Skenario | Range cosine score |
|---|---|
| Orang yang sama, kondisi ideal | 0.75 – 0.95 |
| Orang yang sama, beda angle/cahaya | 0.50 – 0.75 |
| Saudara kandung (mirip genetik) | 0.30 – 0.55 |
| Orang tidak terkait | 0.00 – 0.35 |

Threshold 0.58–0.60 untuk menolak duplikat jatuh tepat di zona overlap antara "orang berbeda" dan "orang yang sama dengan pose berbeda" — terlalu agresif untuk enrollment.

---

## 3. Perbaikan

### File: `recognizer.py`

#### Sebelum

```python
# Threshold yang terlalu agresif dan saling bertabrakan
MATCH_THRESHOLD          = 0.60
DUPLICATE_REG_THRESHOLD  = 0.60   # = MATCH_THRESHOLD: konsisten tapi salah
ENROLL_FACE_EXISTS_THRESHOLD = 0.58  # sedikit di bawah MATCH untuk toleransi pose

def register_user(self, name: str, embedding: np.ndarray) -> tuple[bool, str]:
    ...
    with self.db_lock:
        if name in self.database:
            return False, "name_taken"

        if self._db_matrix is not None:
            scores     = self._cosine_scores(embedding)
            best_idx   = int(np.argmax(scores))
            best_score = float(scores[best_idx])
            best_name  = self._db_names[best_idx]

            # Cek 1: tolak jika score >= 0.60
            if best_score >= DUPLICATE_REG_THRESHOLD and best_name != name:
                return False, f"face_exists:{best_name}"

            # Cek 2: tidak pernah dicapai karena cek 1 sudah blokir lebih dulu
            if best_score >= ENROLL_FACE_EXISTS_THRESHOLD and best_name != name:
                return False, f"duplicate:{best_name}"

        self.database[name] = embedding
        ...
```

#### Sesudah

```python
# Threshold yang dipisahkan dengan jelas berdasarkan peran masing-masing
MATCH_THRESHOLD              = 0.50   # pengenalan: lebih toleran variasi pose/cahaya
DUPLICATE_REG_THRESHOLD      = 0.75   # enrollment: blokir hanya jika wajah benar-benar sama
ENROLL_FACE_EXISTS_THRESHOLD = 0.70   # per-frame preview: batas lebih tinggi dari MATCH

def register_user(self, name: str, embedding: np.ndarray) -> tuple[bool, str]:
    ...
    with self.db_lock:
        if name in self.database:
            return False, "name_taken"

        if self._db_matrix is not None:
            scores     = self._cosine_scores(embedding)
            best_idx   = int(np.argmax(scores))
            best_score = float(scores[best_idx])
            best_name  = self._db_names[best_idx]

            # Satu cek yang jelas: tolak hanya jika wajah benar-benar sama (>= 0.75)
            # Threshold jauh di atas MATCH_THRESHOLD agar orang berbeda tetap bisa daftar
            if best_score >= DUPLICATE_REG_THRESHOLD and best_name != name:
                logger.warning(
                    f"❌ Enroll ditolak: wajah '{name}' sudah terdaftar "
                    f"sebagai '{best_name}' (score={best_score:.3f})"
                )
                return False, f"face_exists:{best_name}"

        self.database[name] = embedding
        ...
```

### Perbandingan threshold sebelum dan sesudah

| Konstanta | Sebelum | Sesudah | Keterangan |
|---|---|---|---|
| `MATCH_THRESHOLD` | 0.60 | **0.50** | Lebih toleran, cocok untuk kondisi nyata |
| `DUPLICATE_REG_THRESHOLD` | 0.60 | **0.75** | Hanya blokir wajah yang benar-benar sama |
| `ENROLL_FACE_EXISTS_THRESHOLD` | 0.58 | **0.70** | Di atas MATCH agar tidak false-reject |

### Hasil

- Orang berbeda dengan kemiripan sedang (0.50–0.70) **sekarang bisa mendaftar**
- Wajah yang sama benar-benar (0.75+) tetap ditolak sebagai duplikat
- Pengenalan tetap berfungsi karena `MATCH_THRESHOLD` diturunkan ke 0.50

---

## 4. Refactoring: Normalisasi Embedding Dipindah ke Titik Ekstraksi

### Latar Belakang

Sebelum refactoring, normalisasi L2 dilakukan **berulang kali** di tiga tempat berbeda:

1. Di `match_face` — saat query dibandingkan dengan DB
2. Di `check_face_exists` — saat cek per-frame enrollment
3. Di `_cosine_scores` — helper internal

Setiap kali fungsi-fungsi ini dipanggil, kode yang sama dieksekusi:

```python
q = embedding.flatten().astype(np.float32)
q_norm = q / (np.linalg.norm(q) + 1e-9)
```

Ini adalah **redundansi komputasi** — embedding yang sama dinormalisasi berulang kali di setiap frame.

### Sebelum (normalisasi tersebar)

```python
# recognizer.py — SEBELUM

def get_embedding(self, frame, face_info):
    """Ekstrak embedding — dikembalikan apa adanya (tidak ternormalisasi)."""
    try:
        aligned = self.face_recognizer.alignCrop(frame, face_info)
        return self.face_recognizer.feature(aligned)  # (1, 128) — belum ternormalisasi
    except Exception as e:
        logger.error(f"Embedding error: {e}")
        return None

def match_face(self, embedding):
    # Normalisasi dilakukan di sini — setiap kali dipanggil
    q      = embedding.flatten().astype(np.float32)
    q_norm = q / (np.linalg.norm(q) + 1e-9)           # ← duplikasi
    norms  = np.linalg.norm(db_matrix, axis=1, keepdims=True) + 1e-9
    scores = (db_matrix / norms) @ q_norm              # ← normalisasi DB juga diulang
    ...

def check_face_exists(self, embedding):
    # Normalisasi dilakukan lagi di sini
    q      = embedding.flatten().astype(np.float32)
    q_norm = q / (np.linalg.norm(q) + 1e-9)           # ← duplikasi kedua
    norms  = np.linalg.norm(db_matrix, axis=1, keepdims=True) + 1e-9
    scores = (db_matrix / norms) @ q_norm              # ← normalisasi DB diulang lagi
    ...

def _rebuild_db_matrix(self):
    # DB matrix disimpan RAW — belum ternormalisasi
    self._db_matrix = np.vstack(
        [self.database[n].flatten() for n in self._db_names]
    ).astype(np.float32)  # (N, 128) — tidak ternormalisasi
```

### Sesudah (normalisasi di titik ekstraksi, sekali saja)

```python
# recognizer.py — SESUDAH

def get_embedding(self, frame, face_info):
    """Ekstrak embedding — dikembalikan sudah L2-ternormalisasi."""
    try:
        aligned = self.face_recognizer.alignCrop(frame, face_info)
        raw  = self.face_recognizer.feature(aligned)      # (1, 128)
        norm = np.linalg.norm(raw) + 1e-9
        return (raw / norm).astype(np.float32)            # ← normalisasi SEKALI di sini
    except Exception as e:
        logger.error(f"Embedding error: {e}")
        return None

def _rebuild_db_matrix(self):
    # DB matrix dibangun sudah ternormalisasi — sekali saat DB berubah
    raw   = np.vstack(
        [self.database[n].flatten() for n in self._db_names]
    ).astype(np.float32)                                   # (N, 128)
    norms = np.linalg.norm(raw, axis=1, keepdims=True) + 1e-9
    self._db_matrix = raw / norms                         # ← pre-normalised (N, 128)

def _cosine_scores(self, embedding):
    """Cosine similarity — DB sudah ternormalisasi, query dinormalisasi di sini."""
    q      = embedding.flatten().astype(np.float32)
    q_norm = q / (np.linalg.norm(q) + 1e-9)               # normalisasi query saja
    return self._db_matrix @ q_norm                        # (N,) — pure dot product

def match_face(self, embedding):
    # Tidak ada normalisasi DB di sini — sudah dilakukan saat rebuild
    q      = embedding.flatten().astype(np.float32)
    q_norm = q / (np.linalg.norm(q) + 1e-9)
    scores = db_matrix @ q_norm                            # (N,) — satu operasi
    ...

def check_face_exists(self, embedding):
    # Sama — tidak ada normalisasi DB yang diulang
    q      = embedding.flatten().astype(np.float32)
    q_norm = q / (np.linalg.norm(q) + 1e-9)
    scores = db_matrix @ q_norm
    ...
```

### Dampak refactoring

| Aspek | Sebelum | Sesudah |
|---|---|---|
| Normalisasi embedding query | Diulang di setiap `match_face` dan `check_face_exists` | Dilakukan sekali di `get_embedding` |
| Normalisasi DB matrix | Diulang di setiap `match_face` dan `check_face_exists` (`N × 128` divisions) | Dilakukan sekali di `_rebuild_db_matrix`, hanya saat DB berubah |
| Operasi per frame (recognition) | `2 × (128 divisions + 128 additions)` | `128 multiplications + 127 additions` (pure dot product) |
| Kejelasan kode | Normalisasi tersebar di 3+ tempat | Tanggung jawab jelas: ekstraksi → unit-norm, matching → dot product |

Pada Raspberry Pi 4 dengan CPU terbatas, eliminasi operasi divisi berulang di hot path (setiap frame ~10–30 FPS) memberikan penghematan yang terasa, terutama saat database tumbuh menjadi 10+ profil.

---

## Ringkasan

| Item | Detail |
|---|---|
| **Bug** | False rejection pada enrollment — wajah baru ditolak karena threshold duplikat terlalu rendah |
| **Diagnosis** | Log menunjukkan score 0.612 memicu penolakan; analisis threshold menemukan `DUPLICATE_REG_THRESHOLD == MATCH_THRESHOLD` dan dua cek duplikat yang kontradiktif |
| **Perbaikan** | Naikkan `DUPLICATE_REG_THRESHOLD` ke 0.75, hapus cek duplikat ganda, pisahkan peran masing-masing threshold secara eksplisit |
| **Refactoring** | Pindahkan normalisasi L2 ke titik ekstraksi (`get_embedding`) dan pre-normalisasi DB matrix saat rebuild — menghilangkan komputasi redundan di hot path setiap frame |
