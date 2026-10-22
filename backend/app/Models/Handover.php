<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Serah terima Lead/Client antar PIC dan divisi, ke arah mana pun. Hanya ditambah, tidak pernah diubah. */
#[Fillable(['client_partner_id', 'from_user_id', 'from_department_id', 'to_user_id', 'to_department_id', 'by_user_id', 'reason', 'deals_moved', 'created_at'])]
class Handover extends Model
{
    public const UPDATED_AT = null;

    public function fromDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }

    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }
}
