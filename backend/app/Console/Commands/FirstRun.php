<?php

namespace App\Console\Commands;

use App\Models\Department;
use Illuminate\Console\Command;

/**
 * Untuk hosting tanpa console (misalnya Render Free): dipanggil setiap container start dan aman diulang.
 * - Database kosong → db:seed (di produksi hanya data referensi, di staging juga data contoh).
 * - OPTIMA_INTERNAL_USERS_JSON terisi → daftarkan akun internal dan ganti akun uji @contoh.test.
 */
class FirstRun extends Command
{
    protected $signature = 'optima:first-run';

    protected $description = 'Isi data awal dan akun internal kalau belum ada (aman dijalankan berulang)';

    public function handle(): int
    {
        if (! Department::query()->exists()) {
            $this->info('Database masih kosong: menjalankan db:seed.');
            $this->call('db:seed', ['--force' => true]);
        }

        $hasList = (string) ($_SERVER['OPTIMA_INTERNAL_USERS_JSON'] ?? $_ENV['OPTIMA_INTERNAL_USERS_JSON'] ?? getenv('OPTIMA_INTERNAL_USERS_JSON') ?: '') !== '';
        if ($hasList) {
            return $this->call('optima:sync-internal-users', ['--from-env' => true, '--replace-demo' => true]);
        }

        return self::SUCCESS;
    }
}
