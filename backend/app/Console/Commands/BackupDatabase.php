<?php

namespace App\Console\Commands;

use App\Services\Audit;
use App\Services\Backup;
use Illuminate\Console\Command;
use Throwable;

/** Backup harian (SRS 8.1). Dijadwalkan di routes/console.php; bisa juga dijalankan manual sebelum perubahan besar. */
class BackupDatabase extends Command
{
    protected $signature = 'optima:backup {--disk= : Disk tujuan (default config optima.backup_disk)}';

    protected $description = 'Buat backup semua data CRM ke disk backup, lalu hapus backup lama';

    public function handle(Backup $backup, Audit $audit): int
    {
        $disk = $this->option('disk') ?: config('optima.backup_disk');
        try {
            $result = $backup->create($disk);
        } catch (Throwable $e) {
            report($e);
            $audit->log('Backup gagal', 'Sistem', null, class_basename($e).': '.$e->getMessage());
            $this->error('Backup gagal: '.$e->getMessage());

            return self::FAILURE;
        }
        $deleted = $backup->prune(config('optima.backup_keep_days'), $disk);

        $total = array_sum($result['counts']);
        $audit->log('Backup', 'Sistem', null, $result['path'].' ('.$disk.'): '.$total.' baris, '.round($result['bytes'] / 1024).' KB'
            .($deleted ? ', '.count($deleted).' backup lama dihapus' : ''));
        $this->info('Backup tersimpan: '.$disk.':'.$result['path'].' ('.$total.' baris, '.round($result['bytes'] / 1024).' KB)');
        foreach ($result['counts'] as $table => $n) {
            $this->line(str_pad($table, 20).$n);
        }

        return self::SUCCESS;
    }
}
