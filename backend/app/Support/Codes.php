<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Kode data yang mengikuti id: CP-0001, DL-0001, INT-0001. Id diambil dulu dari sequence tabelnya. */
class Codes
{
    /** @return array{0: int, 1: string} [id, kode] */
    public static function next(string $table, string $prefix): array
    {
        $id = (int) DB::selectOne("select nextval(pg_get_serial_sequence(?, 'id')) as id", [$table])->id;

        return [$id, $prefix.'-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT)];
    }
}
