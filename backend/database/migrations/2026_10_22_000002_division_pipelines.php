<?php

use App\Support\DivisionDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Struktur divisi (keputusan 22 Okt 2026, mengikuti pratinjau klien):
 * - Commercial dilebur ke Customer Service: pengguna, data, serah terima, dan layanan pindah ke CS.
 * - Satu pipeline per divisi. Deal pindah ke pipeline divisi relasinya (stage dicocokkan lewat nama, Won ke Closing).
 *   Pipeline lama tanpa divisi diarsipkan, tidak dihapus, supaya riwayat stage tetap terbaca.
 * - Layanan CampusNet dan DietPartner ditambahkan.
 * - Hak akses awal per divisi diisi ulang dari DivisionDefaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipelines', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('name')->constrained()->nullOnDelete();
        });

        $cs = DB::table('departments')->where('name', 'Customer Service')->first();
        $commercial = DB::table('departments')->where('name', 'Commercial')->first();
        if ($commercial && $cs) {
            foreach ([['users', 'department_id'], ['client_partners', 'department_id'], ['services', 'department_id'],
                ['handovers', 'from_department_id'], ['handovers', 'to_department_id']] as [$table, $col]) {
                DB::table($table)->where($col, $commercial->id)->update([$col => $cs->id]);
            }
            DB::table('access_grants')->where('department_id', $commercial->id)->delete();
            DB::table('departments')->where('id', $commercial->id)->delete();
            DB::table('audit_logs')->insert(['action' => 'Ubah', 'entity_type' => 'Department', 'entity_id' => $cs->id,
                'summary' => 'Commercial dilebur ke Customer Service (migrasi struktur divisi)', 'created_at' => now()]);
        } elseif ($commercial) {
            DB::table('departments')->where('id', $commercial->id)->update(['name' => 'Customer Service']);
        }

        foreach (['CampusNet', 'DietPartner'] as $name) {
            if (! DB::table('services')->where('name', $name)->exists()) {
                DB::table('services')->insert(['name' => $name, 'department_id' => DB::table('departments')->where('name', $name)->value('id'),
                    'position' => (int) DB::table('services')->max('position') + 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        foreach (DB::table('departments')->get() as $dept) {
            foreach (DivisionDefaults::access($dept->name) as $entity => $level) {
                DB::table('access_grants')->updateOrInsert(['department_id' => $dept->id, 'entity' => $entity], ['level' => $level, 'updated_at' => now()]);
            }
            $this->ensurePipeline($dept);
        }

        // Deal di pipeline lama (tanpa divisi) pindah ke pipeline divisi pemilik relasinya.
        $legacy = DB::table('pipelines')->whereNull('department_id')->pluck('id');
        foreach (DB::table('deals')->whereIn('pipeline_id', $legacy)->get() as $deal) {
            $deptId = DB::table('client_partners')->where('id', $deal->client_partner_id)->value('department_id');
            $target = DB::table('pipelines')->where('department_id', $deptId)->whereNull('archived_at')->orderBy('id')->first();
            if (! $target) {
                continue;
            }
            $old = DB::table('stages')->where('id', $deal->stage_id)->first();
            $stages = DB::table('stages')->where('pipeline_id', $target->id)->orderBy('position')->get();
            $stage = $deal->status === 'Won'
                ? $stages->firstWhere('is_closing', true)
                : ($stages->first(fn ($s) => ! $s->is_closing && strcasecmp($s->name, (string) $old?->name) === 0) ?? $stages->where('is_closing', false)->first());
            DB::table('deals')->where('id', $deal->id)->update(['pipeline_id' => $target->id, 'stage_id' => $stage->id]);
        }
        DB::table('pipelines')->whereNull('department_id')->whereNull('archived_at')->update(['archived_at' => now()]);
    }

    private function ensurePipeline(object $dept): void
    {
        if (DB::table('pipelines')->where('department_id', $dept->id)->exists()) {
            return;
        }
        $name = DB::table('pipelines')->where('name', $dept->name)->exists() ? $dept->name.' (divisi)' : $dept->name;
        $id = DB::table('pipelines')->insertGetId(['name' => $name, 'department_id' => $dept->id,
            'position' => (int) DB::table('pipelines')->max('position') + 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (DivisionDefaults::STAGES as $i => $stage) {
            DB::table('stages')->insert(['pipeline_id' => $id, 'name' => $stage, 'position' => $i, 'is_closing' => $stage === 'Closing',
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Penggabungan department dan perpindahan deal tidak bisa dibalik otomatis; kolom divisi pipeline dilepas saja.
        Schema::table('pipelines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
