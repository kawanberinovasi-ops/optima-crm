<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Pemberitahuan di aplikasi (lonceng di navbar). Tetap tampil walaupun email gagal terkirim (SRS 8.2). */
class Notify
{
    public static function send(User|int $user, string $text, string $link = ''): void
    {
        DB::table('user_notifications')->insert([
            'user_id' => $user instanceof User ? $user->id : $user,
            'text' => $text,
            'link' => $link ?: null,
            'created_at' => now(),
        ]);
    }
}
