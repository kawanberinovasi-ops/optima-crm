<?php

namespace App\Http\Controllers;

use App\Models\Pipeline;
use App\Models\Stage;
use App\Services\Access;
use App\Services\Audit;
use App\Support\FrontShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengaturan pipeline (SRS 4.3): lini bisnis dan stage bisa ditambah, diganti nama, dan dihapus tanpa developer.
 * Setiap pipeline punya tepat satu stage Closing di urutan terakhir.
 */
class PipelineController extends Controller
{
    public function __construct(private Access $access, private Audit $audit) {}

    public function store(Request $request): JsonResponse
    {
        $this->authorizeConfig($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:pipelines,name'],
            'stages' => ['nullable', 'string', 'max:2000'],
        ], ['name.required' => 'Nama wajib diisi.', 'name.unique' => 'Nama pipeline ini sudah dipakai.']);

        $names = collect(preg_split('/\r?\n/', (string) ($data['stages'] ?? '')))
            ->map(fn ($s) => trim($s))->filter()->reject(fn ($s) => strtolower($s) === 'closing')->unique()->values();
        if ($names->isEmpty()) {
            $names = collect(['Lead']);
        }

        $pipeline = DB::transaction(function () use ($data, $names) {
            $pipeline = Pipeline::create(['name' => trim($data['name']), 'position' => (int) Pipeline::max('position') + 1]);
            foreach ($names->push('Closing') as $i => $name) {
                $pipeline->stages()->create(['name' => $name, 'position' => $i, 'is_closing' => $name === 'Closing']);
            }

            return $pipeline;
        });
        $this->audit->log('Tambah', 'Pipeline', $pipeline->id, $pipeline->name.' dengan stage '.$names->implode(', ').', Closing');

        return $this->respond($pipeline, 201);
    }

    /** Stage baru disisipkan setelah stage tertentu (atau di awal), selalu sebelum Closing. */
    public function storeStage(Request $request, Pipeline $pipeline): JsonResponse
    {
        $this->authorizeConfig($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'after' => ['nullable', 'integer']], ['name.required' => 'Nama stage wajib diisi.']);
        $this->assertUniqueName($pipeline, $data['name']);

        DB::transaction(function () use ($pipeline, $data) {
            $stages = $pipeline->stages()->get();
            $after = isset($data['after']) ? $stages->firstWhere('id', (int) $data['after']) : null;
            $pos = $after && ! $after->is_closing ? $after->position + 1 : ($after ? $after->position : 0);
            $pipeline->stages()->where('position', '>=', $pos)->increment('position');
            $pipeline->stages()->create(['name' => trim($data['name']), 'position' => $pos]);
        });
        $this->audit->log('Tambah', 'Stage', $pipeline->id, trim($data['name']).' di '.$pipeline->name);

        return $this->respond($pipeline);
    }

    public function updateStage(Request $request, Stage $stage): JsonResponse
    {
        $this->authorizeConfig($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:100']], ['name.required' => 'Nama stage wajib diisi.']);
        if ($stage->is_closing) {
            throw ValidationException::withMessages(['name' => 'Stage Closing ga bisa diganti nama.']);
        }
        $this->assertUniqueName($stage->pipeline, $data['name'], $stage->id);

        $old = $stage->name;
        $stage->update(['name' => trim($data['name'])]);
        $this->audit->log('Ubah', 'Stage', $stage->pipeline_id, $old.' jadi '.$stage->name.' di '.$stage->pipeline->name, ['name' => $old], ['name' => $stage->name]);

        return $this->respond($stage->pipeline);
    }

    public function destroyStage(Request $request, Stage $stage): JsonResponse
    {
        $this->authorizeConfig($request);
        abort_if($stage->is_closing, 422, 'Stage Closing ga bisa dihapus.');
        $count = $stage->pipeline->deals()->where('stage_id', $stage->id)->count();
        abort_if($count > 0, 422, 'Masih ada '.$count.' deal di stage '.$stage->name.'. Pindahkan dulu deal-nya.');
        abort_if($stage->pipeline->stages()->where('is_closing', false)->count() <= 1, 422, 'Pipeline butuh minimal satu stage selain Closing.');

        $pipeline = $stage->pipeline;
        DB::transaction(function () use ($stage, $pipeline) {
            $pos = $stage->position;
            // Riwayat lama tetap menyimpan nama stage, jadi referensinya cukup dilepas.
            DB::table('deal_histories')->where('from_stage_id', $stage->id)->update(['from_stage_id' => null]);
            DB::table('deal_histories')->where('to_stage_id', $stage->id)->update(['to_stage_id' => null]);
            $stage->delete();
            $pipeline->stages()->where('position', '>', $pos)->decrement('position');
        });
        $this->audit->log('Hapus', 'Stage', $pipeline->id, $stage->name.' dari '.$pipeline->name);

        return $this->respond($pipeline);
    }

    private function assertUniqueName(Pipeline $pipeline, string $name, ?int $except = null): void
    {
        $taken = $pipeline->stages()->whereRaw('lower(name) = ?', [strtolower(trim($name))])->when($except, fn ($q) => $q->where('id', '<>', $except))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'Nama stage udah dipakai di pipeline ini.']);
        }
    }

    private function authorizeConfig(Request $request): void
    {
        abort_unless($this->access->canConfigurePipeline($request->user()), 403, 'Cuma Super Admin dan Kepala Department yang bisa mengatur pipeline.');
    }

    private function respond(Pipeline $pipeline, int $status = 200): JsonResponse
    {
        return response()->json(['data' => FrontShape::line($pipeline->fresh('stages'))], $status);
    }
}
