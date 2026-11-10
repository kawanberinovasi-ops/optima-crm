<?php

namespace App\Models;

use App\Support\ContactIndex;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kontak milik satu data Client & Partner (FR-REL-02).
 * Email dan telepon disimpan terenkripsi (APP_KEY); email_hash/phone_hash untuk cek duplikat (ContactIndex).
 */
#[Fillable(['client_partner_id', 'name', 'title', 'email', 'phone', 'social', 'is_primary'])]
class Contact extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'email' => 'encrypted', 'phone' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::saving(function (Contact $contact) {
            $contact->email_hash = ContactIndex::email($contact->email);
            $contact->phone_hash = ContactIndex::phone($contact->phone);
        });
    }

    public function clientPartner(): BelongsTo
    {
        return $this->belongsTo(ClientPartner::class);
    }
}
