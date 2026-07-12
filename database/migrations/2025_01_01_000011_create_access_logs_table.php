<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel: access_logs
 * ──────────────────
 * Menyimpan setiap kejadian akses pintu — berhasil maupun gagal.
 * Ini adalah versi relasional dari history.json yang ada di Raspberry Pi.
 * Sumber data: dikirim dari FastAPI backend saat unlock terjadi,
 * atau di-sync manual dari history.json.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Nama yang dikenali, atau "Unknown"');
            $table->enum('status', ['granted', 'denied'])->default('granted')
                  ->comment('granted = akses diberikan, denied = ditolak');
            $table->decimal('similarity', 5, 2)->default(0)
                  ->comment('Persentase kemiripan wajah (0–100)');
            $table->string('snapshot_file')->nullable()
                  ->comment('Nama file WebP snapshot dari Raspberry Pi');
            $table->string('camera_node')->nullable()->default('/dev/video0')
                  ->comment('Node kamera yang mendeteksi');
            $table->string('ip_address', 45)->nullable()
                  ->comment('IP Raspberry Pi yang mengirim event');
            $table->timestamp('accessed_at')->useCurrent()
                  ->comment('Waktu akses terjadi (dari Pi)');
            $table->timestamps();

            // Index untuk query yang sering digunakan
            $table->index(['name', 'accessed_at']);
            $table->index('status');
            $table->index('accessed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_logs');
    }
};
