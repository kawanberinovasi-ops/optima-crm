<?php

namespace App\Support;

/**
 * Indeks pencarian untuk email dan telepon kontak yang disimpan terenkripsi (blind index HMAC-SHA256).
 * Nilai yang sama selalu menghasilkan hash yang sama, jadi cek duplikat tetap bisa dilakukan di database
 * tanpa menyimpan email/telepon dalam bentuk terbaca.
 */
class ContactIndex
{
    public static function email(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email === '' ? null : hash_hmac('sha256', $email, AppKey::derive('contact-index'));
    }

    /** Telepon dicocokkan dari 8 digit terakhir (mengabaikan +62/0 di depan), minimal 7 digit. */
    public static function phone(?string $phone): ?string
    {
        $last = self::digits8($phone);

        return strlen($last) >= 7 ? hash_hmac('sha256', $last, AppKey::derive('contact-index')) : null;
    }

    public static function digits8(?string $phone): string
    {
        return substr(preg_replace('/\D/', '', (string) $phone), -8);
    }
}
