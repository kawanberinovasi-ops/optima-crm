<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Console\Command;

/**
 * Daftarkan Super Admin pertama di server baru (SRS bagian 11: minimal 2 Super Admin dari klien).
 * Setelah itu pengguna lain didaftarkan lewat halaman Akses & Audit.
 */
class CreateAdmin extends Command
{
    protected $signature = 'optima:create-admin {email} {name} {--dept=Executive : Department pengguna}';

    protected $description = 'Daftarkan atau aktifkan pengguna sebagai Super Admin';

    public function handle(Audit $audit): int
    {
        $email = strtolower(trim($this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email tidak valid.');

            return self::FAILURE;
        }
        $dept = Department::where('name', $this->option('dept'))->first();
        if (! $dept) {
            $this->error('Department "'.$this->option('dept').'" belum ada. Jalankan php artisan db:seed --force dulu.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['email' => $email], [
            'name' => $this->argument('name'), 'department_id' => $dept->id, 'role' => Role::SuperAdmin, 'active' => true, 'deactivated_at' => null,
        ]);
        $audit->log($user->wasRecentlyCreated ? 'Tambah' : 'Ubah', 'Pengguna', $user->id, $user->name.' jadi Super Admin lewat perintah server');
        $this->info($user->email.' sekarang Super Admin dan bisa login lewat SSO.');

        return self::SUCCESS;
    }
}
