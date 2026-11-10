<?php

namespace App\Services;

use App\Support\AppKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Backup harian aplikasi (SRS 8.1) dan pemulihannya (SRS 9.4: backup harus terbukti bisa dipulihkan).
 *
 * Format: .ndjson.gz. Baris pertama header, lalu satu baris per data {"t": tabel, "r": baris},
 * dan baris terakhir footer berisi jumlah baris dan checksum SHA-256 per tabel. Ditulis dan dibaca per baris,
 * jadi hemat memori walaupun audit log besar. Sesi, cache, antrian, dan kode login tidak ikut.
 *
 * Sejak 10 Nov 2026 file dienkripsi (.ndjson.gz.enc): libsodium secretstream XChaCha20-Poly1305 per potongan 64 KB,
 * jadi isi tidak terbaca dan perubahan atau pemotongan file terdeteksi. Kunci: OPTIMA_BACKUP_KEY (base64 32 byte)
 * kalau diisi, kalau tidak diturunkan dari APP_KEY. File lama tanpa enkripsi tetap bisa dipulihkan.
 */
class Backup
{
    /** Urutan mengikuti foreign key, supaya pemulihan bisa memasukkan data apa adanya. */
    public const TABLES = [
        'departments', 'users', 'user_emails', 'access_grants', 'access_overrides', 'option_lists', 'services',
        'pipelines', 'stages', 'department_pipeline', 'pipeline_user',
        'client_partners', 'contacts', 'deals', 'deal_histories', 'interactions', 'handovers',
        'audit_logs', 'imports', 'import_rows', 'saved_views', 'user_notifications', 'trash_entries',
    ];

    /** Tabel penghubung tanpa kolom id: diurutkan lewat kolom kuncinya. */
    private const NO_ID = ['department_pipeline' => ['department_id', 'pipeline_id'], 'pipeline_user' => ['pipeline_id', 'user_id']];

    private const MAGIC = 'OPTIMAENC1';

    private const CHUNK = 65536;

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
                $rows = isset(self::NO_ID[$table])
                    ? DB::table($table)->orderBy(self::NO_ID[$table][0])->orderBy(self::NO_ID[$table][1])->cursor()
                    : DB::table($table)->orderBy('id')->lazyById(1000);
                $rows->each(function ($row) use ($table, $hash, $write, &$counts) {
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
        if (config('optima.backup_encrypt')) {
            $plain = $tmp;
            $tmp = tempnam(sys_get_temp_dir(), 'optima-backup-enc-');
            self::encryptFile($plain, $tmp);
            unlink($plain);
            $path .= '.enc';
        }
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
            if (preg_match('/optima-crm-(\d{8}-\d{6})\.ndjson\.gz(\.enc)?$/', $file, $m) && $m[1] < $cutoff) {
                Storage::disk($disk)->delete($file);
                $deleted[] = $file;
            }
        }

        return $deleted;
    }

    public function latest(?string $disk = null): ?string
    {
        $files = collect(Storage::disk($disk ?? config('optima.backup_disk'))->files('backups'))
            ->filter(fn ($f) => (bool) preg_match('/optima-crm-\d{8}-\d{6}\.ndjson\.gz(\.enc)?$/', $f))->sort()->values();

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

        $plain = $tmp;
        try {
            if (self::isEncrypted($tmp)) {
                $plain = tempnam(sys_get_temp_dir(), 'optima-restore-plain-');
                self::decryptFile($tmp, $plain);
            }

            return DB::transaction(fn () => $this->load($plain));
        } finally {
            unlink($tmp);
            $plain !== $tmp && is_file($plain) && unlink($plain);
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
        // Backup lama belum memuat tabel yang ditambahkan belakangan: cukup cocokkan tabel yang tercatat di header.
        foreach (array_intersect(self::TABLES, $header['tables'] ?? self::TABLES) as $table) {
            if (($footer['counts'][$table] ?? null) !== $counts[$table] || ($footer['sha256'][$table] ?? null) !== hash_final($hashes[$table])) {
                throw new RuntimeException('Isi tabel '.$table.' tidak cocok dengan checksum backup.');
            }
        }
        foreach ($merged as $id => $into) {
            DB::table('client_partners')->where('id', $id)->update(['merged_into_id' => $into]);
        }
        foreach (array_diff(self::TABLES, array_keys(self::NO_ID)) as $table) {
            DB::statement("select setval(pg_get_serial_sequence(?, 'id'), greatest(coalesce((select max(id) from {$table}), 0), 1), (select count(*) > 0 from {$table}))", [$table]);
        }

        return $counts;
    }

    public static function isEncrypted(string $file): bool
    {
        $f = fopen($file, 'rb');
        $head = fread($f, strlen(self::MAGIC));
        fclose($f);

        return $head === self::MAGIC;
    }

    public static function encryptFile(string $in, string $out): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push(self::key());
        fwrite($dst, self::MAGIC.$header);
        $chunk = fread($src, self::CHUNK);
        do {
            $next = fread($src, self::CHUNK);
            $last = $next === '' || $next === false;
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, (string) $chunk, '',
                $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            fwrite($dst, pack('N', strlen($cipher)).$cipher);
            $chunk = $next;
        } while (! $last);
        fclose($src);
        fclose($dst);
    }

    public static function decryptFile(string $in, string $out): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        try {
            if (fread($src, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Ini bukan backup terenkripsi OPTIMA CRM.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(
                (string) fread($src, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), self::key());
            $final = false;
            while (! $final && ($len = fread($src, 4)) !== false && strlen($len) === 4) {
                $cipher = (string) fread($src, unpack('N', $len)[1]);
                $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($res === false) {
                    throw new RuntimeException('Backup tidak bisa didekripsi: kunci salah atau file berubah.');
                }
                fwrite($dst, $res[0]);
                $final = $res[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (! $final) {
                throw new RuntimeException('File backup terpotong.');
            }
        } finally {
            fclose($src);
            fclose($dst);
        }
    }

    private static function key(): string
    {
        $key = (string) config('optima.backup_key');
        if ($key !== '') {
            $raw = base64_decode($key, true);
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
                throw new RuntimeException('OPTIMA_BACKUP_KEY harus base64 dari 32 byte (openssl rand -base64 32).');
            }

            return $raw;
        }

        return AppKey::derive('backup');
    }
}
