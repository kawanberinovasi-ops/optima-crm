<?php

namespace Database\Seeders;

use App\Models\AccessGrant;
use App\Models\Department;
use App\Models\OptionList;
use App\Models\Pipeline;
use App\Models\Service;
use Illuminate\Database\Seeder;

/**
 * Pengaturan awal yang dipakai juga di produksi: department dari blueprint, matriks hak akses usulan tim
 * (SRS bagian 3), daftar pilihan, dan pipeline per jenis pekerjaan (Sales, Campaign) beserta divisi pemakainya. Super Admin bisa mengubah semuanya setelah go-live.
 * Sumbernya sample.json, hasil ekspor dari prototipe, supaya backend dan prototipe memakai angka yang sama.
 */
class ReferenceDataSeeder extends Seeder
{
    private const OPTION_KEYS = [
        'segments' => 'segments',
        'sources' => 'sources',
        'intTypes' => 'interaction_types',
        'lostReasons' => 'lost_reasons',
    ];

    public function run(): void
    {
        $data = SampleData::load();

        foreach ($data['departments'] as $name) {
            Department::firstOrCreate(['name' => $name], ['sees_all' => in_array($name, $data['sees_all'] ?? [], true)]);
        }

        // Layanan dan divisi yang menanganinya. Hanya mengisi yang belum diatur, supaya ubahan admin tidak tertimpa.
        foreach ($data['services'] ?? [] as $i => $svc) {
            $service = Service::firstOrCreate(['name' => $svc['name']], ['position' => $i]);
            if (! $service->department_id && $svc['department']) {
                $service->update(['department_id' => Department::where('name', $svc['department'])->value('id')]);
            }
        }

        foreach ($data['access'] as $deptName => $grants) {
            $deptId = Department::where('name', $deptName)->value('id');
            foreach ($grants as $entity => $level) {
                AccessGrant::updateOrCreate(['department_id' => $deptId, 'entity' => $entity], ['level' => $level]);
            }
        }

        foreach (self::OPTION_KEYS as $from => $list) {
            foreach ($data['options'][$from] as $i => $value) {
                OptionList::updateOrCreate(['list' => $list, 'value' => $value], ['position' => $i]);
            }
        }

        foreach ($data['pipelines'] as $i => $p) {
            $pipeline = Pipeline::firstOrCreate(['name' => $p['name']], ['position' => $i]);
            // Divisi yang memakai pipeline ini; hanya diisi kalau belum diatur, supaya ubahan Super Admin tidak tertimpa.
            if (! $pipeline->departments()->exists()) {
                $pipeline->departments()->sync(Department::whereIn('name', $p['departments'] ?? [])->pluck('id'));
            }
            if ($pipeline->stages()->exists()) {
                continue;
            }
            $last = count($p['stages']) - 1;
            foreach ($p['stages'] as $pos => $stage) {
                $pipeline->stages()->create(['name' => $stage, 'position' => $pos, 'is_closing' => $pos === $last]);
            }
        }
    }
}
