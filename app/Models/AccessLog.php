<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Model: AccessLog
 * ────────────────
 * Setiap baris = satu event akses pintu (berhasil atau gagal).
 * Sumber data: dikirim dari FastAPI (Raspberry Pi) saat unlock terjadi,
 * atau disinkronisasi dari history.json.
 *
 * @property int         $id
 * @property string      $name
 * @property string      $status        'granted' | 'denied'
 * @property float       $similarity    0–100 (persentase)
 * @property string|null $snapshot_file nama file WebP di Pi
 * @property string|null $camera_node
 * @property string|null $ip_address
 * @property \Carbon\Carbon $accessed_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class AccessLog extends Model
{
    protected $fillable = [
        'name',
        'status',
        'similarity',
        'snapshot_file',
        'camera_node',
        'ip_address',
        'accessed_at',
    ];

    protected $casts = [
        'similarity'  => 'decimal:2',
        'accessed_at' => 'datetime',
    ];

    // ── Scope ──────────────────────────────────────────────────────────────────

    /** Hanya log akses diterima */
    public function scopeGranted(Builder $query): Builder
    {
        return $query->where('status', 'granted');
    }

    /** Hanya log akses ditolak */
    public function scopeDenied(Builder $query): Builder
    {
        return $query->where('status', 'denied');
    }

    /** Log hari ini */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('accessed_at', today());
    }

    /** Log N hari terakhir */
    public function scopeLastDays(Builder $query, int $days = 7): Builder
    {
        return $query->where('accessed_at', '>=', now()->subDays($days));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** URL snapshot gambar dari Raspberry Pi */
    public function snapshotUrl(string $backendUrl): string
    {
        if (!$this->snapshot_file) {
            return '';
        }
        return rtrim($backendUrl, '/') . '/history/' . $this->snapshot_file;
    }

    /** Label status dalam Bahasa Indonesia */
    public function statusLabel(): string
    {
        return $this->status === 'granted' ? 'Diterima' : 'Ditolak';
    }
}
