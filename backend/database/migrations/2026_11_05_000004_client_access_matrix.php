<?php

use App\Support\DivisionDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hak akses awal sesuai keputusan klien (tabel "3. Hak akses awal", 5 Nov 2026), termasuk entitas baru "partner".
 * Pengecualian per orang yang sudah dibuat Super Admin tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['access_grants', 'access_overrides'] as $table) {
            DB::statement("alter table {$table} drop constraint if exists {$table}_level_check");
            DB::statement("alter table {$table} add constraint {$table}_level_check check ("
                ."(entity in ('lead','client','partner','interaksi','deal') and level in ('N','R','C','M'))"
                ." or (entity = 'nilai' and level in ('Ya','Ya*','Lihat*','Tidak'))"
                ." or (entity = 'rate' and level in ('Ya','M','R*','Tidak')))");
        }
        foreach (DB::table('departments')->get() as $dept) {
            foreach (DivisionDefaults::access($dept->name) as $entity => $level) {
                DB::table('access_grants')->updateOrInsert(['department_id' => $dept->id, 'entity' => $entity], ['level' => $level, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::table('access_grants')->where('entity', 'partner')->delete();
        DB::table('access_overrides')->where('entity', 'partner')->delete();
        foreach (['access_grants', 'access_overrides'] as $table) {
            DB::statement("alter table {$table} drop constraint if exists {$table}_level_check");
            DB::statement("alter table {$table} add constraint {$table}_level_check check ("
                ."(entity in ('lead','client','interaksi','deal') and level in ('N','R','C','M'))"
                ." or (entity = 'nilai' and level in ('Ya','Ya*','Lihat*','Tidak'))"
                ." or (entity = 'rate' and level in ('Ya','M','R*','Tidak')))");
        }
    }
};
