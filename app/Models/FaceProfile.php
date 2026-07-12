<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model: FaceProfile
 * ──────────────────
 * Representasi pengguna terdaftar dalam sistem face recognition.
 * Satu profil = satu orang = satu embedding wajah di database.json Pi.
 *
 * @property int         $id
 * @property string      $name
 * @property string|null $registered_by
 * @property bool        $is_active
 * @property int         $access_count
 * @property \Carbon\Carbon|null $last_access_at
 * @property \Carbon\Carbon      $created_at
 * @property \Carbon\Carbon      $updated_at
 */
class FaceProfile extends Model
{
    protected $fillable = [
        'name',
        'registered_by',
        'is_active',
        'access_count',
        'last_access_at',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'access_count'   => 'integer',
        'last_access_at' => 'datetime',
    ];

    // ── Relasi ─────────────────────────────────────────────────────────────────

    /** Semua log akses untuk profil ini */
    public function accessLogs(): HasMany
    {
        return $this->hasMany(AccessLog::class, 'name', 'name');
    }

    // ── Scope ──────────────────────────────────────────────────────────────────

    /** Hanya profil aktif */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Increment counter akses dan update waktu akses terakhir */
    public function recordAccess(): void
    {
        $this->increment('access_count');
        $this->update(['last_access_at' => now()]);
    }
}
