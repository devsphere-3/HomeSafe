<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Model: MotionLog
 * ────────────────
 * Setiap baris = satu event "Ada Tamu Datang" dari kamera CCTV.
 * Dicatat setelah cooldown 8 detik (tidak setiap frame).
 *
 * @property int         $id
 * @property float       $area_percentage persentase layar yang bergerak
 * @property string|null $camera_node
 * @property string|null $ip_address
 * @property \Carbon\Carbon $detected_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class MotionLog extends Model
{
    protected $fillable = [
        'area_percentage',
        'camera_node',
        'ip_address',
        'detected_at',
    ];

    protected $casts = [
        'area_percentage' => 'decimal:2',
        'detected_at'     => 'datetime',
    ];

    // ── Scope ──────────────────────────────────────────────────────────────────

    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('detected_at', today());
    }

    public function scopeLastDays(Builder $query, int $days = 7): Builder
    {
        return $query->where('detected_at', '>=', now()->subDays($days));
    }
}
