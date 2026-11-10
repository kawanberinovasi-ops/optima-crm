<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Setiap divisi memakai pipelinenya sendiri (revisi klien 10 Nov 2026): hak Deal minimal C, supaya pipeline yang diatur
 * Super Admin benar-benar muncul dan deal milik sendiri bisa digeser. Customer Service mengelola deal (M).
 * Pipeline mana yang dipakai tetap diatur di Akses & Audit → Pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('access_grants')->where('entity', 'deal')->whereIn('level', ['N', 'R'])->update(['level' => 'C', 'updated_at' => now()]);
        $cs = DB::table('departments')->where('name', 'Customer Service')->value('id');
        DB::table('access_grants')->where(['department_id' => $cs, 'entity' => 'deal'])->update(['level' => 'M', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Tidak dibalik otomatis.
    }
};
