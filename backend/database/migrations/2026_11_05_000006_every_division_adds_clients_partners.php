<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Setiap divisi bisa menambah Client (lewat Lead) dan Partner sendiri (revisi klien 5 Nov 2026):
 * hak Lead, Client, dan Partner yang masih N atau R dinaikkan ke C. Yang sudah C atau M tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('access_grants')->whereIn('entity', ['lead', 'client', 'partner'])->whereIn('level', ['N', 'R'])
            ->update(['level' => 'C', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Tidak dibalik otomatis: level awal tiap divisi berbeda.
    }
};
