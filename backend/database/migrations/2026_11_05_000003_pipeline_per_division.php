<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Revisi klien 5 Nov 2026 (lanjutan): setiap divisi punya pipeline sendiri.
 * Pipeline Sales dipecah: Customer Service memakai pipeline Sales yang lama (diganti nama), divisi lain mendapat pipeline
 * baru bernama divisinya dengan stage yang sama, dan deal pindah ke pipeline divisi PIC relasinya (stage dicocokkan lewat nama).
 * Pipeline Campaign tetap untuk Digital Campaign. Pengecualian per pengguna (pipeline_user) tetap berlaku.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sales = DB::table('pipelines')->where('name', 'Sales')->whereNull('archived_at')->first();
        if (! $sales) {
            return; // database kosong: pipeline dibuat seeder
        }
        $depts = DB::table('departments')->join('department_pipeline', 'department_pipeline.department_id', '=', 'departments.id')
            ->where('department_pipeline.pipeline_id', $sales->id)->orderBy('departments.id')->get(['departments.id', 'departments.name']);
        $keeper = $depts->firstWhere('name', 'Customer Service') ?? $depts->first();
        if (! $keeper) {
            return;
        }
        DB::table('pipelines')->where('id', $sales->id)->update(['name' => $keeper->name, 'updated_at' => now()]);
        $stages = DB::table('stages')->where('pipeline_id', $sales->id)->orderBy('position')->get();

        foreach ($depts->where('id', '<>', $keeper->id) as $dept) {
            $name = DB::table('pipelines')->where('name', $dept->name)->exists() ? $dept->name.' (divisi)' : $dept->name;
            $id = DB::table('pipelines')->insertGetId(['name' => $name, 'position' => (int) DB::table('pipelines')->max('position') + 1,
                'created_at' => now(), 'updated_at' => now()]);
            $map = [];
            foreach ($stages as $s) {
                $map[$s->id] = DB::table('stages')->insertGetId(['pipeline_id' => $id, 'name' => $s->name, 'position' => $s->position,
                    'is_closing' => $s->is_closing, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('department_pipeline')->where(['department_id' => $dept->id, 'pipeline_id' => $sales->id])->delete();
            DB::table('department_pipeline')->insert(['department_id' => $dept->id, 'pipeline_id' => $id]);

            // Deal milik relasi yang PIC-nya di divisi ini ikut ke pipeline divisi itu.
            $records = DB::table('client_partners')->join('users', 'users.id', '=', 'client_partners.pic_id')
                ->where('users.department_id', $dept->id)->pluck('client_partners.id');
            foreach (DB::table('deals')->where('pipeline_id', $sales->id)->whereIn('client_partner_id', $records)->get() as $deal) {
                DB::table('deals')->where('id', $deal->id)->update(['pipeline_id' => $id, 'stage_id' => $map[$deal->stage_id]]);
                $stage = $stages->firstWhere('id', $deal->stage_id);
                DB::table('deal_histories')->insert(['deal_id' => $deal->id, 'from_stage_id' => null, 'from_stage_name' => $stage->name,
                    'to_stage_id' => $map[$deal->stage_id], 'to_stage_name' => $stage->name, 'status' => $deal->status,
                    'note' => 'Pipeline Sales dipecah per divisi: pindah ke '.$name, 'created_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Pemecahan pipeline tidak dibalik otomatis.
    }
};
