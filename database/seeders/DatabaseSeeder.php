<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Jalankan semua: php artisan db:seed
     * Jalankan spesifik: php artisan db:seed --class=HomeSafeSeeder
     */
    public function run(): void
    {
        $this->call([
            HomeSafeSeeder::class,
        ]);
    }
}
