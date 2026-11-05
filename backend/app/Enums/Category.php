<?php

namespace App\Enums;

/**
 * Status relasi Client & Partner. Client: data baru Lead, berubah jadi Client hanya lewat deal Won.
 * Partner (vendor, media partner, KOL, komunitas) langsung tercatat sebagai Partner tanpa tahap Lead (revisi klien 5 Nov 2026).
 * KOL, Community, Media, dan Digital Campaign bukan status, tapi layanan (tabel services).
 */
enum Category: string
{
    case Lead = 'Lead';
    case Client = 'Client';
    case Partner = 'Partner';

    /** Nama entitas di matriks hak akses. */
    public function entity(): string
    {
        return match ($this) {
            self::Lead => 'lead',
            self::Client, self::Partner => 'client',
        };
    }
}
