<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel: motion_logs
 * ──────────────────
 * Menyimpan event deteksi gerakan dari kamera CCTV (kamera halaman).
 * Setiap baris = satu event "Ada Tamu Datang" setelah cooldown 8 detik.
 * Data dikirim dari frontend via API atau dapat diisi manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motion_logs', function (Blueprint $table) {
            $table->id();
            $table->decimal('area_percentage', 5, 2)->default(0)
                  ->comment('Persentase area layar yang bergerak (0–100)');
            $table->string('camera_node')->nullable()->default('/dev/video2')
                  ->comment('Node kamera CCTV');
            $table->string('ip_address', 45)->nullable()
                  ->comment('IP Raspberry Pi');
            $table->timestamp('detected_at')->useCurrent()
                  ->comment('Waktu deteksi gerakan');
            $table->timestamps();

            $table->index('detected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motion_logs');
    }
};
