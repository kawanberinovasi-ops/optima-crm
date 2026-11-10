<?php

namespace App\Support;

use RuntimeException;

/**
 * Kunci turunan dari APP_KEY untuk keperluan terpisah (backup, indeks pencarian kontak), lewat HKDF supaya
 * kunci satu keperluan tidak bisa dipakai untuk keperluan lain. APP_KEY wajib ikut dipindah bersama data
 * saat serah terima: tanpa APP_KEY yang sama, kolom terenkripsi dan backup tidak bisa dibaca.
 */
class AppKey
{
    public static function raw(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('APP_KEY belum diisi.');
        }

        return str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
    }

    public static function derive(string $purpose): string
    {
        return hash_hkdf('sha256', self::raw(), 32, 'optima-crm:'.$purpose);
    }
}
