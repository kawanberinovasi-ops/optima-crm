<?php

namespace App\Enums;

/** Level akses data: N tidak ada, R lihat, C tambah dan ubah punya sendiri, M kelola semua (SRS bagian 3). */
enum AccessLevel: string
{
    case N = 'N';
    case R = 'R';
    case C = 'C';
    case M = 'M';

    public function rank(): int
    {
        return match ($this) {
            self::N => 0,
            self::R => 1,
            self::C => 2,
            self::M => 3,
        };
    }

    public function atLeast(self $level): bool
    {
        return $this->rank() >= $level->rank();
    }
}
