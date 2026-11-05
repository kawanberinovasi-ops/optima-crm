<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi klien 5 Nov 2026.
 * - Pipeline mengikuti jenis pekerjaan, bukan satu per divisi: Sales (bawaan semua divisi) dan Campaign (Digital Campaign).
 *   Divisi memakai pipeline lewat department_pipeline; Super Admin bisa menambah atau mencabut pipeline per pengguna
 *   lewat pipeline_user. Deal dari pipeline divisi lain pindah ke Sales (stage dicocokkan lewat nama), pipeline yang
 *   kosong dihapus. Stage pipeline Digital Campaign diganti nama sesuai urutannya jadi stage Campaign.
 * - Status relasi bertambah Partner (tanpa tahap Lead).
 */
return new class extends Migration
{
    private const CAMPAIGN_STAGES = ['Brief', 'Konsep', 'Produksi', 'Tayang', 'Report'];

    public function up(): void
    {
        Schema::create('department_pipeline', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->primary(['department_id', 'pipeline_id']);
        });
        Schema::create('pipeline_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            // true = tambahan di luar pipeline divisinya, false = dicabut walaupun divisinya memakai pipeline itu.
            $table->boolean('allowed');
            $table->primary(['user_id', 'pipeline_id']);
        });

        $this->mergeDivisionPipelines();

        Schema::table('pipelines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        DB::statement('alter table client_partners drop constraint if exists client_partners_category_check');
        DB::statement("alter table client_partners add constraint client_partners_category_check check (category in ('Lead', 'Client', 'Partner'))");
    }

    private function mergeDivisionPipelines(): void
    {
        $byDept = fn (string $name) => DB::table('pipelines')->join('departments', 'departments.id', '=', 'pipelines.department_id')
            ->where('departments.name', $name)->whereNull('pipelines.archived_at')->value('pipelines.id');
        $sales = $byDept('Customer Service') ?? DB::table('pipelines')->whereNull('archived_at')->whereNotNull('department_id')
            ->when($byDept('Digital Campaign'), fn ($q, $dc) => $q->where('id', '<>', $dc))->orderBy('position')->value('id');
        if (! $sales) {
            return; // database kosong: pipeline dibuat seeder
        }
        $campaign = $byDept('Digital Campaign');

        DB::table('pipelines')->where('id', $sales)->update(['name' => 'Sales', 'position' => 0]);
        if ($campaign) {
            DB::table('pipelines')->where('id', $campaign)->update(['name' => 'Campaign', 'position' => 1]);
            $open = DB::table('stages')->where('pipeline_id', $campaign)->where('is_closing', false)->orderBy('position')->get();
            foreach ($open as $i => $stage) {
                if (isset(self::CAMPAIGN_STAGES[$i])) {
                    DB::table('stages')->where('id', $stage->id)->update(['name' => self::CAMPAIGN_STAGES[$i]]);
                }
            }
        }

        $salesStages = DB::table('stages')->where('pipeline_id', $sales)->orderBy('position')->get();
        $salesOpen = $salesStages->where('is_closing', false)->values();
        $others = DB::table('pipelines')->whereNotIn('id', array_filter([$sales, $campaign]))->whereNotNull('department_id')->pluck('id');
        foreach (DB::table('deals')->whereIn('pipeline_id', $others)->get() as $deal) {
            $old = DB::table('stages')->where('id', $deal->stage_id)->first();
            $oldIndex = DB::table('stages')->where('pipeline_id', $deal->pipeline_id)->where('is_closing', false)->where('position', '<', (int) $old?->position)->count();
            $target = $deal->status === 'Won' || $old?->is_closing
                ? $salesStages->firstWhere('is_closing', true)
                : ($salesOpen->first(fn ($s) => strcasecmp($s->name, (string) $old?->name) === 0) ?? $salesOpen->get(min($oldIndex, $salesOpen->count() - 1)));
            DB::table('deals')->where('id', $deal->id)->update(['pipeline_id' => $sales, 'stage_id' => $target->id]);
            DB::table('deal_histories')->insert(['deal_id' => $deal->id, 'from_stage_id' => $old?->id, 'from_stage_name' => $old?->name,
                'to_stage_id' => $target->id, 'to_stage_name' => $target->name, 'status' => $deal->status,
                'note' => 'Pipeline divisi digabung ke Sales', 'created_at' => now()]);
        }
        $emptied = DB::table('pipelines')->whereIn('id', $others)->pluck('id');
        $emptyStages = DB::table('stages')->whereIn('pipeline_id', $emptied)->pluck('id');
        DB::table('deal_histories')->whereIn('from_stage_id', $emptyStages)->update(['from_stage_id' => null]);
        DB::table('deal_histories')->whereIn('to_stage_id', $emptyStages)->update(['to_stage_id' => null]);
        DB::table('pipelines')->whereIn('id', $emptied)->delete();

        $dc = DB::table('departments')->where('name', 'Digital Campaign')->value('id');
        foreach (DB::table('departments')->pluck('id') as $deptId) {
            DB::table('department_pipeline')->insert(['department_id' => $deptId, 'pipeline_id' => $campaign && $deptId === $dc ? $campaign : $sales]);
        }
    }

    public function down(): void
    {
        DB::statement('alter table client_partners drop constraint if exists client_partners_category_check');
        DB::statement("alter table client_partners add constraint client_partners_category_check check (category in ('Lead', 'Client'))");
        Schema::table('pipelines', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('name')->constrained()->nullOnDelete();
        });
        Schema::dropIfExists('pipeline_user');
        Schema::dropIfExists('department_pipeline');
    }
};
