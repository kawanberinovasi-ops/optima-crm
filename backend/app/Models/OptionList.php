<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Daftar pilihan yang diatur admin: segmen, sumber, jenis interaksi, alasan Lost (FR-USR-06). */
#[Fillable(['list', 'value', 'position', 'active'])]
class OptionList extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
