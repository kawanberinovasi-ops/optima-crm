<?php

namespace App\Enums;

/**
 * Status relasi Client & Partner. Data baru selalu Lead; berubah jadi Client hanya lewat deal Won.
 * KOL, Community, Media, dan Digital Campaign bukan status, tapi layanan (tabel services).
 */
enum Category: string
{
    case Lead = 'Lead';
    case Client = 'Client';

    /** Nama entitas di matriks hak akses. */
    public function entity(): string
    {
        return match ($this) {
            self::Lead => 'lead',
            self::Client => 'client',
        };
    }
}
