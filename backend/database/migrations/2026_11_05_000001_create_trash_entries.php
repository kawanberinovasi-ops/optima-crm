<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data terhapus (mengikuti pratinjau klien 27 Okt 2026): hapus selalu bisa dipulihkan, tidak ada penghapusan permanen otomatis.
 * Client & Partner disembunyikan dengan deleted_at (interaksi dan deal ikut tersembunyi karena semua query lewat relasinya),
 * pengguna ditandai deleted_at dan dinonaktifkan supaya namanya tetap terbaca di riwayat lama.
 * Data lain (lini bisnis, stage, layanan, department, pengecualian) disimpan salinannya di trash_entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trash_entries', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('label');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('snapshot');
            $table->timestamp('created_at')->useCurrent();
            $table->index('kind');
        });

        Schema::table('client_partners', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('deleted_at'));
        Schema::table('client_partners', fn (Blueprint $table) => $table->dropSoftDeletes());
        Schema::dropIfExists('trash_entries');
    }
};
