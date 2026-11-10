<?php

namespace App\Support;

/**
 * Mencegah CSV/formula injection: sel yang diawali = + - @ tab atau CR akan dijalankan Excel sebagai rumus
 * (misalnya =HYPERLINK(...) dari nama klien atau isi catatan). Sel seperti itu diawali tanda kutip tunggal.
 */
class CsvSafe
{
    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
