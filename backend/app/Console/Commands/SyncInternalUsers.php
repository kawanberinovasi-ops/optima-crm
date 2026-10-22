<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use App\Models\UserEmail;
use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daftarkan akun internal OPTIMA dari file JSON yang TIDAK ikut git (identitas asli hanya untuk lingkungan internal).
 *
 * Format file: [{"department": "...", "name": "...", "email": "...", "alternate_emails": ["..."], "role": "Kepala Department"}]
 * - Satu orang = satu pengguna. Email alternatif disimpan di user_emails, jadi login lewat email mana pun masuk ke identitas yang sama.
 * - Role default Kepala Department. Tidak ada yang otomatis jadi Super Admin.
 * - Aman dijalankan berulang: pengguna yang sudah ada diperbarui, tidak dibuat ganda.
 * Menyiapkan daftar ini tidak mengaktifkan SSO; login Google butuh GOOGLE_CLIENT_ID dan GOOGLE_CLIENT_SECRET.
 */
class SyncInternalUsers extends Command
{
    protected $signature = 'optima:sync-internal-users {file : Path file JSON di luar repo, misalnya storage/app/private/internal-users.json} {--dry-run : Periksa tanpa menyimpan}';

    protected $description = 'Daftarkan atau perbarui akun internal (kepala department) dari file JSON privat';

    public function handle(Audit $audit): int
    {
        $path = $this->argument('file');
        $rows = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($rows)) {
            $this->error('File tidak ada atau bukan JSON yang valid: '.$path);

            return self::FAILURE;
        }

        $errors = [];
        foreach ($rows as $i => $r) {
            $emails = array_map(fn ($e) => strtolower(trim((string) $e)), array_merge([$r['email'] ?? ''], $r['alternate_emails'] ?? []));
            foreach ($emails as $e) {
                if (! filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Baris '.($i + 1).': email "'.$e.'" tidak valid.';
                }
            }
            if (! Department::where('name', $r['department'] ?? '')->exists()) {
                $errors[] = 'Baris '.($i + 1).': department "'.($r['department'] ?? '').'" belum ada.';
            }
            if (! Role::tryFrom($r['role'] ?? 'Kepala Department')) {
                $errors[] = 'Baris '.($i + 1).': role "'.$r['role'].'" tidak dikenal.';
            }
        }
        $all = collect($rows)->flatMap(fn ($r) => array_map('strtolower', array_merge([$r['email'] ?? ''], $r['alternate_emails'] ?? [])));
        foreach ($all->duplicates() as $dup) {
            $errors[] = 'Email '.$dup.' muncul lebih dari sekali di file.';
        }
        if ($errors) {
            foreach ($errors as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->info(count($rows).' akun valid. Tidak ada yang disimpan (--dry-run).');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows, $audit) {
            foreach ($rows as $r) {
                $emails = array_map(fn ($e) => strtolower(trim($e)), array_merge([$r['email']], $r['alternate_emails'] ?? []));
                // Orang yang sama bisa sudah terdaftar lewat email utama atau alternatifnya.
                $user = collect($emails)->map(fn ($e) => User::findByAnyEmail($e))->filter()->unique('id');
                if ($user->count() > 1) {
                    throw new \RuntimeException('Email '.implode(', ', $emails).' sudah dipakai oleh lebih dari satu pengguna. Gabungkan manual dulu.');
                }
                $user = $user->first() ?? new User;
                $created = ! $user->exists;
                $user->fill([
                    'name' => $r['name'], 'email' => $emails[0], 'role' => Role::from($r['role'] ?? 'Kepala Department'),
                    'department_id' => Department::where('name', $r['department'])->value('id'), 'active' => true, 'deactivated_at' => null,
                ])->save();
                UserEmail::where('user_id', $user->id)->whereNotIn('email', array_slice($emails, 1))->delete();
                foreach (array_slice($emails, 1) as $alt) {
                    UserEmail::firstOrCreate(['email' => $alt], ['user_id' => $user->id]);
                }
                $audit->log($created ? 'Tambah' : 'Ubah', 'Pengguna', $user->id, $user->name.' ('.$user->role->value.', '.$r['department'].') dari daftar akun internal');
                $this->line(($created ? 'baru     ' : 'diperbarui').'  '.$r['department'].': '.$user->name.' ('.count($emails).' email)');
            }
        });
        $this->info(count($rows).' akun internal siap. SSO belum aktif sampai GOOGLE_CLIENT_ID diisi.');

        return self::SUCCESS;
    }
}
