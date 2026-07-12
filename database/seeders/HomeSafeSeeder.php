<?php

namespace Database\Seeders;

use App\Models\FaceProfile;
use App\Models\AccessLog;
use App\Models\MotionLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * HomeSafeSeeder
 * ──────────────
 * Sinkronisasi data NYATA dari Raspberry Pi ke SQLite Laravel.
 * Tidak ada data dummy — semua dari file JSON Pi.
 *
 * Jalankan setelah Pi menyala dan JSON sudah terisi:
 *   php artisan db:seed --class=HomeSafeSeeder
 *
 * Variabel environment (opsional, default ke path relatif):
 *   BACKEND_DB_PATH   = path ke database.json  (profil wajah)
 *   BACKEND_HIST_PATH = path ke history.json   (log akses)
 *   PI_IP             = IP Raspberry Pi (default 172.20.10.3)
 */
class HomeSafeSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🌱 HomeSafe Seeder — sinkronisasi data dari Raspberry Pi…');

        $synced = 0;
        $synced += $this->syncFaceProfiles();
        $synced += $this->syncAccessLogs();

        if ($synced === 0) {
            $this->command->warn(
                '⚠️  Tidak ada file JSON ditemukan. ' .
                'Pastikan Raspberry Pi sudah berjalan dan path benar.'
            );
            $this->command->line('   Cek path:');
            $this->command->line('   BACKEND_DB_PATH   = ' .
                env('BACKEND_DB_PATH', base_path('../backend/database.json')));
            $this->command->line('   BACKEND_HIST_PATH = ' .
                env('BACKEND_HIST_PATH', base_path('../backend/history.json')));
        } else {
            $this->command->info('✅ Sinkronisasi selesai.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FACE PROFILES — dari database.json Pi
    // ─────────────────────────────────────────────────────────────────────────
    private function syncFaceProfiles(): int
    {
        $dbPath = env('BACKEND_DB_PATH', base_path('../backend/database.json'));

        if (!file_exists($dbPath)) {
            $this->command->warn("⏭  database.json tidak ditemukan ({$dbPath}) — profil dilewati.");
            return 0;
        }

        $raw   = json_decode(file_get_contents($dbPath), true) ?? [];
        $names = array_keys($raw);

        if (empty($names)) {
            $this->command->warn('⏭  database.json kosong — tidak ada profil untuk disinkronkan.');
            return 0;
        }

        // Hapus profil yang sudah tidak ada di Pi
        FaceProfile::whereNotIn('name', $names)->delete();

        $added = 0;
        foreach ($names as $name) {
            FaceProfile::firstOrCreate(
                ['name' => $name],
                [
                    'registered_by'  => 'Pi',
                    'is_active'      => true,
                    'access_count'   => 0,
                    'last_access_at' => null,
                ]
            );
            $added++;
        }

        $this->command->info("👤 {$added} profil wajah disinkronkan ke face_profiles.");
        return $added;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ACCESS LOGS — dari history.json Pi
    // ─────────────────────────────────────────────────────────────────────────
    private function syncAccessLogs(): int
    {
        $histPath = env('BACKEND_HIST_PATH', base_path('../backend/history.json'));

        if (!file_exists($histPath)) {
            $this->command->warn("⏭  history.json tidak ditemukan ({$histPath}) — log akses dilewati.");
            return 0;
        }

        $records = json_decode(file_get_contents($histPath), true) ?? [];

        if (empty($records)) {
            $this->command->warn('⏭  history.json kosong — tidak ada log untuk disinkronkan.');
            return 0;
        }

        // Hapus semua lalu isi ulang agar selalu sinkron dengan Pi
        AccessLog::truncate();

        $count = 0;
        foreach ($records as $entry) {
            $name      = $entry['name'] ?? 'Unknown';
            $pct       = (float) ($entry['percentage'] ?? 0);
            $accessedAt = Carbon::parse($entry['timestamp'] ?? now());

            AccessLog::create([
                'name'          => $name,
                'status'        => 'granted',
                'similarity'    => $pct,
                'snapshot_file' => $entry['image'] ?? null,
                'camera_node'   => '/dev/video0',
                'ip_address'    => env('PI_IP', '172.20.10.3'),
                'accessed_at'   => $accessedAt,
            ]);

            // Update access_count & last_access_at di face_profiles
            $profile = FaceProfile::where('name', $name)->first();
            if ($profile) {
                $profile->access_count++;
                if (!$profile->last_access_at || $accessedAt->gt($profile->last_access_at)) {
                    $profile->last_access_at = $accessedAt;
                }
                $profile->save();
            }

            $count++;
        }

        $this->command->info("🔑 {$count} log akses disinkronkan ke access_logs.");
        return $count;
    }
}
