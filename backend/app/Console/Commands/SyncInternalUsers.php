<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use App\Models\UserEmail;
use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
    protected $signature = 'optima:sync-internal-users {file? : Path file JSON di luar repo, misalnya storage/app/private/internal-users.json} {--from-env : Baca JSON (atau base64 dari JSON) dari environment variable OPTIMA_INTERNAL_USERS_JSON, untuk server tanpa akses file seperti Laravel Cloud} {--dry-run : Periksa tanpa menyimpan} {--replace-demo : Hapus akun uji @contoh.test; data mereka dialihkan ke kepala divisinya}';

    protected $description = 'Daftarkan atau perbarui akun internal (kepala department) dari file JSON privat';

    public function handle(Audit $audit): int
    {
        if ($this->option('from-env')) {
            // Dibaca langsung dari proses, bukan config(), supaya tetap terbaca setelah php artisan optimize.
            $raw = trim((string) ($_SERVER['OPTIMA_INTERNAL_USERS_JSON'] ?? $_ENV['OPTIMA_INTERNAL_USERS_JSON'] ?? getenv('OPTIMA_INTERNAL_USERS_JSON') ?: ''));
            $raw = str_starts_with($raw, '[') ? $raw : (string) base64_decode($raw, true);
            $rows = json_decode($raw, true);
            $source = 'environment variable OPTIMA_INTERNAL_USERS_JSON';
        } else {
            $path = (string) $this->argument('file');
            $rows = $path !== '' && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $source = 'file '.($path ?: '(tidak diisi)');
        }
        if (! is_array($rows)) {
            $this->error('Daftar akun tidak ada atau bukan JSON yang valid: '.$source);

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
        if ($this->option('replace-demo')) {
            $this->replaceDemoAccounts($audit);
        }

        return self::SUCCESS;
    }

    /**
     * Akun uji @contoh.test dihapus. Semua yang mereka pegang (relasi, deal, interaksi, riwayat) dialihkan ke kepala
     * department divisi yang sama, atau ke Super Admin kalau divisinya belum punya kepala. Audit log lama tidak
     * dialihkan supaya tidak tampak dikerjakan orang lain (nama pelakunya jadi kosong).
     */
    private function replaceDemoAccounts(Audit $audit): void
    {
        $demo = User::where('email', 'like', '%@contoh.test')->get();
        $real = User::where('email', 'not like', '%@contoh.test')->where('active', true)->get();
        $admin = $real->firstWhere('role', Role::SuperAdmin);
        if (! $admin) {
            $this->error('Belum ada Super Admin di akun internal; akun uji tidak dihapus.');

            return;
        }
        $refs = collect(DB::select("select kcu.table_name, kcu.column_name from information_schema.table_constraints tc
            join information_schema.key_column_usage kcu on tc.constraint_name = kcu.constraint_name and tc.table_schema = kcu.table_schema
            join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name and ccu.table_schema = tc.table_schema
            where tc.constraint_type = 'FOREIGN KEY' and ccu.table_name = 'users' and ccu.column_name = 'id' and tc.table_schema = current_schema()"));
        // Baris milik pribadi akun uji dihapus saja; audit log dibiarkan kosong pelakunya.
        $personal = ['access_overrides', 'user_emails', 'pipeline_user', 'saved_views', 'user_notifications', 'audit_logs'];

        DB::transaction(function () use ($demo, $real, $admin, $refs, $personal, $audit) {
            foreach ($demo as $user) {
                $to = $real->first(fn ($u) => $u->department_id === $user->department_id && $u->role === Role::KepalaDepartment) ?? $admin;
                foreach ($refs as $ref) {
                    if (in_array($ref->table_name, $personal, true)) {
                        continue;
                    }
                    DB::table($ref->table_name)->where($ref->column_name, $user->id)->update([$ref->column_name => $to->id]);
                }
                foreach (array_diff($personal, ['audit_logs']) as $table) {
                    if (Schema::hasColumn($table, 'user_id')) {
                        DB::table($table)->where('user_id', $user->id)->delete();
                    }
                }
                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
                $user->delete();
                $this->line('dihapus    '.$user->email.' (data dialihkan ke '.$to->name.')');
            }
            $audit->log('Hapus', 'Pengguna', null, $demo->count().' akun uji dihapus; datanya dialihkan ke kepala divisinya');
        });
    }
}
