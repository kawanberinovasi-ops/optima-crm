<?php

namespace App\Enums;

/** Kategori data Client & Partner (SRS 4.2). */
enum Category: string
{
    case Lead = 'Lead';
    case Client = 'Client';
    case Community = 'Community';
    case KolKoc = 'KOL/KOC';
    case Media = 'Media';

    /** Nama entitas di matriks hak akses. */
    public function entity(): string
    {
        return match ($this) {
            self::Lead => 'lead',
            self::Client => 'client',
            self::Community => 'community',
            self::KolKoc => 'kol',
            self::Media => 'media',
        };
    }
}
