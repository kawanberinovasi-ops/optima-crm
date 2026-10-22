<?php

namespace App\Support;

/**
 * Pengaturan awal per divisi (keputusan 22 Okt 2026, mengikuti pratinjau klien):
 * Executive dan Customer Service mengelola (M), divisi lain menambah dan mengubah datanya sendiri (C).
 * Dipakai migrasi, seeder, dan department baru, supaya angkanya sama di semua tempat.
 */
class DivisionDefaults
{
    public const STAGES = ['Lead', 'Qualified', 'Meeting', 'Proposal', 'Negotiation', 'Closing'];

    /** @return array<string, string> entitas => level */
    public static function access(string $department): array
    {
        $level = in_array($department, ['Executive', 'Customer Service'], true) ? 'M' : 'C';

        return [
            'lead' => $level, 'client' => $level, 'interaksi' => $level, 'deal' => $level,
            'nilai' => $department === 'Executive' ? 'Ya' : 'Ya*',
            'rate' => in_array($department, ['Executive', 'Digital Campaign'], true) ? 'M' : 'Tidak',
        ];
    }
}
