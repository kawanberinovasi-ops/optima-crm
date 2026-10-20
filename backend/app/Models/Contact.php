<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Kontak milik satu data Client & Partner (FR-REL-02). */
#[Fillable(['client_partner_id', 'name', 'title', 'email', 'phone', 'social', 'is_primary'])]
class Contact extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function clientPartner(): BelongsTo
    {
        return $this->belongsTo(ClientPartner::class);
    }
}
