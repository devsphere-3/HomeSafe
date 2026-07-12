<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel: face_profiles
 * ────────────────────
 * Menyimpan metadata pengguna yang terdaftar di sistem Face Recognition.
 * Embedding wajah (128-dimensi float) tetap disimpan di database.json pada
 * Raspberry Pi karena dibutuhkan oleh engine OpenCV secara lokal.
 * Tabel ini adalah "mirror" metadata-nya untuk keperluan dashboard & laporan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->comment('Nama pengguna, harus unik');
            $table->string('registered_by')->nullable()->comment('Siapa yang mendaftarkan');
            $table->boolean('is_active')->default(true)->comment('Apakah profil aktif');
            $table->integer('access_count')->default(0)->comment('Total akses berhasil');
            $table->timestamp('last_access_at')->nullable()->comment('Waktu akses terakhir');
            $table->timestamps(); // created_at = waktu pendaftaran
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_profiles');
    }
};
