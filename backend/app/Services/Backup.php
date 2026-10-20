<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Backup harian aplikasi (SRS 8.1) dan pemulihannya (SRS 9.4: backup harus terbukti bisa dipulihkan).
 *
 * Format: satu file .ndjson.gz. Baris pertama header, lalu satu baris per data {"t": tabel, "r": baris},
 * dan baris terakhir footer berisi jumlah baris dan checksum SHA-256 per tabel. Ditulis dan dibaca per baris,
 * jadi hemat memori walaupun audit log besar. Sesi, cache, dan antrian tidak ikut (pengguna cukup login lagi).
 */
class Backup
{
    /** Urutan mengikuti foreign key, supaya pemulihan bisa memasukkan data apa adanya. */
    public const TABLES = [
        'departments', 'users', 'access_grants', 'access_overrides', 'option_lists',
        'pipelines', 'stages', 'client_partners', 'contacts', 'deals', 'deal_histories', 'interactions',
        'audit_logs', 'imports', 'import_rows', 'saved_views', 'user_notifications',
    ];

    public const PREFIX = 'backups/optima-crm-';

    /** @return array{path: string, counts: array<string, int>, bytes: int} */
    public function create(?string $disk = null): array
    {
        $disk ??= config('optima.backup_disk');
        $tmp = tempnam(sys_get_temp_dir(), 'optima-backup-');
        $gz = gzopen($tmp, 'wb6');
        $write = fn (array $line) => gzwrite($gz, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $write(['backup' => 'optima-crm', 'format' => 1, 'created_at' => now()->toIso8601String(),
            'migration' => DB::table('migrations')->orderByDesc('id')->value('migration'), 'tables' => self::TABLES]);

        $counts = [];
        $sums = [];
        // Snapshot yang konsisten: semua tabel dibaca dalam satu transaksi REPEATABLE READ.
        DB::transaction(function () use (&$counts, &$sums, $write) {
            if (DB::transactionLevel() === 1) {
                DB::statement('set transaction isolation level repeatable read, read only');
            }
            foreach (self::TABLES as $table) {
                $hash = hash_init('sha256');
                $counts[$table] = 0;
                DB::table($table)->orderBy('id')->lazyById(1000)->each(function ($row) use ($table, $hash, $write, &$counts) {
                    $row = (array) $row;
                    hash_update($hash, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $write(['t' => $table, 'r' => $row]);
                    $counts[$table]++;
                });
                $sums[$table] = hash_final($hash);
            }
        });
        $write(['end' => true, 'counts' => $counts, 'sha256' => $sums]);
        gzclose($gz);

        $path = self::PREFIX.now()->format('Ymd-His').'.ndjson.gz';
        $stream = fopen($tmp, 'rb');
        Storage::disk($disk)->writeStream($path, $stream);
        fclose($stream);
        $bytes = filesize($tmp);
        unlink($tmp);

        return ['path' => $path, 'counts' => $counts, 'bytes' => $bytes];
    }

    /** Hapus backup yang lebih lama dari batas hari. @return list<string> */
    public function prune(int $keepDays, ?string $disk = null): array
    {
        $disk ??= config('optima.backup_disk');
        $cutoff = now()->subDays($keepDays)->format('Ymd-His');
        $deleted = [];
        foreach (Storage::disk($disk)->files('backups') as $file) {
            if (preg_match('/optima-crm-(\d{8}-\d{6})\.ndjson\.gz$/', $file, $m) && $m[1] < $cutoff) {
                Storage::disk($disk)->delete($file);
                $deleted[] = $file;
            }
        }

        return $deleted;
    }

    public function latest(?string $disk = null): ?string
    {
        $files = collect(Storage::disk($disk ?? config('optima.backup_disk'))->files('backups'))
            ->filter(fn ($f) => str_ends_with($f, '.ndjson.gz'))->sort()->values();

        return $files->last();
    }

    /**
     * Pulihkan ke database yang tabel-tabelnya masih kosong (misalnya cluster baru hasil `php artisan migrate`).
     * Checksum dan jumlah baris dicocokkan dengan footer; kalau tidak cocok, semuanya dibatalkan.
     *
     * @return array<string, int> jumlah baris per tabel
     */
    public function restore(string $path, ?string $disk = null): array
    {
        $disk ??= config('optima.backup_disk');
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Tabel '.$table.' tidak kosong. Pulihkan ke database baru yang sudah di-migrate.');
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'optima-restore-');
        $src = Storage::disk($disk)->readStream($path) ?? throw new RuntimeException('File backup tidak ada: '.$path);
        $dst = fopen($tmp, 'wb');
        stream_copy_to_stream($src, $dst);
        fclose($dst);
        is_resource($src) && fclose($src);

        try {
            return DB::transaction(fn () => $this->load($tmp));
        } finally {
            unlink($tmp);
        }
    }

    /** @return array<string, int> */
    private function load(string $file): array
    {
        $gz = gzopen($file, 'rb');
        $header = json_decode((string) gzgets($gz), true);
        if (($header['backup'] ?? null) !== 'optima-crm') {
            throw new RuntimeException('Ini bukan file backup OPTIMA CRM.');
        }

        $counts = array_fill_keys(self::TABLES, 0);
        $hashes = array_map(fn () => hash_init('sha256'), array_flip(self::TABLES));
        $batch = [];
        $batchTable = null;
        $merged = [];
        $footer = null;
        $flush = function () use (&$batch, &$batchTable) {
            if ($batch) {
                DB::table($batchTable)->insert($batch);
                $batch = [];
            }
        };

        while (($line = gzgets($gz)) !== false) {
            $item = json_decode($line, true);
            if (isset($item['end'])) {
                $footer = $item;
                break;
            }
            $table = $item['t'];
            $row = $item['r'];
            if (! isset($counts[$table])) {
                throw new RuntimeException('Tabel tidak dikenal di backup: '.$table);
            }
            hash_update($hashes[$table], json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $counts[$table]++;
            // Data hasil gabung menunjuk ke data lain di tabel yang sama: isi setelah semua baris masuk.
            if ($table === 'client_partners' && $row['merged_into_id'] !== null) {
                $merged[$row['id']] = $row['merged_into_id'];
                $row['merged_into_id'] = null;
            }
            if ($table !== $batchTable || count($batch) >= 500) {
                $flush();
                $batchTable = $table;
            }
            $batch[] = $row;
        }
        $flush();
        gzclose($gz);

        if (! $footer) {
            throw new RuntimeException('File backup terpotong (footer tidak ada).');
        }
        foreach (self::TABLES as $table) {
            if (($footer['counts'][$table] ?? null) !== $counts[$table] || ($footer['sha256'][$table] ?? null) !== hash_final($hashes[$table])) {
                throw new RuntimeException('Isi tabel '.$table.' tidak cocok dengan checksum backup.');
            }
        }
        foreach ($merged as $id => $into) {
            DB::table('client_partners')->where('id', $id)->update(['merged_into_id' => $into]);
        }
        foreach (self::TABLES as $table) {
            DB::statement("select setval(pg_get_serial_sequence(?, 'id'), greatest(coalesce((select max(id) from {$table}), 0), 1), (select count(*) > 0 from {$table}))", [$table]);
        }

        return $counts;
    }
}
