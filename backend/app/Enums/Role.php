<?php

namespace App\Enums;

/** Role pengguna (SRS bagian 3). */
enum Role: string
{
    case SuperAdmin = 'Super Admin';
    case KepalaDepartment = 'Kepala Department';
    case Koordinator = 'Koordinator';
    case Staf = 'Staf';
    case Viewer = 'Viewer';

    /** Kepala Department dan Koordinator: dipakai untuk aturan bertanda * di matriks hak akses. */
    public function isLead(): bool
    {
        return $this === self::KepalaDepartment || $this === self::Koordinator;
    }
}
