<?php

namespace Tests\Concerns;

use App\Models\AccessGrant;
use App\Models\Department;

/**
 * Tes alur bisnis (deal, serah terima, import, dst.) memakai matriks uji yang longgar: Executive dan Customer Service M,
 * divisi lain C, untuk semua data. Angka matriks klien yang sebenarnya diuji terpisah di AccessMatrixTest.
 * Hak nilai deal dan rate card tetap mengikuti matriks klien.
 */
trait OpenAccessMatrix
{
    protected function openAccessMatrix(): void
    {
        foreach (Department::all() as $dept) {
            $level = in_array($dept->name, ['Executive', 'Customer Service'], true) ? 'M' : 'C';
            foreach (['lead', 'client', 'partner', 'interaksi', 'deal'] as $entity) {
                AccessGrant::updateOrCreate(['department_id' => $dept->id, 'entity' => $entity], ['level' => $level]);
            }
        }
    }
}
