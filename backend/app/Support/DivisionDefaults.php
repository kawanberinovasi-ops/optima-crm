<?php

namespace App\Support;

/**
 * Hak akses awal per divisi: keputusan klien di tabel "3. Hak akses awal" (5 Nov 2026).
 * - Commercial sudah dilebur ke Customer Service, jadi Customer Service memakai barisnya sendiri.
 * - Kolom Community, KOL/KOC, dan Media sekarang layanan; hak Partner diambil dari level tertinggi ketiganya.
 * - Setiap divisi bisa menambah Client (lewat Lead) dan Partner sendiri: Lead, Client, dan Partner minimal C (5 Nov 2026).
 * Dipakai migrasi, seeder, dan department baru, supaya angkanya sama di semua tempat.
 */
class DivisionDefaults
{
    public const STAGES = ['Lead', 'Qualified', 'Meeting', 'Proposal', 'Negotiation', 'Closing'];

    /** Department => [lead, client, partner, interaksi, deal, nilai, rate] */
    public const MATRIX = [
        'Executive' => ['M', 'M', 'M', 'M', 'M', 'Ya', 'Ya'],
        'Customer Service' => ['C', 'C', 'C', 'M', 'R', 'Tidak', 'R*'],
        'Digital Campaign' => ['C', 'C', 'M', 'C', 'N', 'Tidak', 'M'],
        'Creative & Media' => ['C', 'C', 'M', 'C', 'N', 'Tidak', 'M'],
        'CampusNet' => ['C', 'C', 'M', 'C', 'N', 'Tidak', 'M'],
        'DietPartner' => ['C', 'C', 'M', 'C', 'N', 'Tidak', 'M'],
        'Business Operation' => ['C', 'M', 'C', 'R', 'M', 'Ya', 'M'],
    ];

    /** Department baru di luar tabel: menambah dan mengubah datanya sendiri. */
    private const NEW_DEPARTMENT = ['C', 'C', 'C', 'C', 'C', 'Ya*', 'Tidak'];

    /** @return array<string, string> entitas => level */
    public static function access(string $department): array
    {
        return array_combine(['lead', 'client', 'partner', 'interaksi', 'deal', 'nilai', 'rate'], self::MATRIX[$department] ?? self::NEW_DEPARTMENT);
    }
}
