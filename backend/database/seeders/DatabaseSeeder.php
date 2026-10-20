<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Data referensi selalu dimuat. Data contoh dan akun uji hanya di luar produksi:
     * data asli OPTIMA baru masuk saat migrasi akhir (SRS 8.3).
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        if (! app()->isProduction()) {
            $this->call(SampleDataSeeder::class);
        }
    }
}
