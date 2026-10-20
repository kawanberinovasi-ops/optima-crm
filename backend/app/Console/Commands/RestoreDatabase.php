<?php

namespace App\Console\Commands;

use App\Services\Backup;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pulihkan backup ke database baru yang sudah di-migrate dan masih kosong (SRS 8.1: pulih dalam 8 jam kerja).
 * Dipakai juga untuk uji pemulihan sebelum go-live (SRS 9.4). Lihat docs/deploy-laravel-cloud.md.
 */
class RestoreDatabase extends Command
{
    protected $signature = 'optima:restore {path? : Path file di disk backup} {--latest : Pakai backup terbaru} {--disk= : Disk sumber} {--force : Jalan tanpa konfirmasi}';

    protected $description = 'Pulihkan backup OPTIMA CRM ke database kosong';

    public function handle(Backup $backup): int
    {
        $disk = $this->option('disk') ?: config('optima.backup_disk');
        $path = $this->argument('path') ?: ($this->option('latest') ? $backup->latest($disk) : null);
        if (! $path) {
            $this->error('Sebutkan path backup, atau pakai --latest.');

            return self::FAILURE;
        }
        $db = config('database.connections.'.config('database.default').'.database');
        if (! $this->option('force') && ! $this->confirm('Pulihkan '.$disk.':'.$path.' ke database "'.$db.'"? Database harus kosong.')) {
            return self::FAILURE;
        }

        try {
            $counts = $backup->restore($path, $disk);
        } catch (Throwable $e) {
            $this->error('Pemulihan dibatalkan, tidak ada data yang masuk: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Pemulihan selesai, checksum semua tabel cocok: '.array_sum($counts).' baris.');
        foreach ($counts as $table => $n) {
            $this->line(str_pad($table, 20).$n);
        }

        return self::SUCCESS;
    }
}
