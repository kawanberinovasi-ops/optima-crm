<?php

use App\Support\ContactIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enkripsi data pribadi saat disimpan (10 Nov 2026):
 * - contacts.email dan contacts.phone dienkripsi dengan APP_KEY; email_hash/phone_hash (HMAC) untuk cek duplikat.
 * - trash_entries.snapshot dienkripsi (bisa berisi data kontak atau akun yang dihapus).
 * Data lama dienkripsi di tempat. Setelah ini APP_KEY wajib ikut dipindah bersama database dan backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            // Indeks lama atas teks terbaca tidak berguna lagi untuk ciphertext; diganti indeks hash.
            $t->dropIndex(['email']);
            $t->dropIndex(['phone']);
            $t->text('email')->nullable()->change();
            $t->text('phone')->nullable()->change();
            $t->string('email_hash', 64)->nullable()->index();
            $t->string('phone_hash', 64)->nullable()->index();
        });
        DB::table('contacts')->orderBy('id')->lazyById(500)->each(function ($c) {
            DB::table('contacts')->where('id', $c->id)->update([
                'email' => $c->email === null ? null : Crypt::encryptString($c->email),
                'phone' => $c->phone === null ? null : Crypt::encryptString($c->phone),
                'email_hash' => ContactIndex::email($c->email),
                'phone_hash' => ContactIndex::phone($c->phone),
            ]);
        });

        Schema::table('trash_entries', fn (Blueprint $t) => $t->text('snapshot')->nullable()->change());
        DB::table('trash_entries')->orderBy('id')->lazyById(500)->each(function ($e) {
            if ($e->snapshot !== null) {
                DB::table('trash_entries')->where('id', $e->id)->update(['snapshot' => Crypt::encryptString($e->snapshot)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('trash_entries')->orderBy('id')->lazyById(500)->each(function ($e) {
            if ($e->snapshot !== null) {
                DB::table('trash_entries')->where('id', $e->id)->update(['snapshot' => Crypt::decryptString($e->snapshot)]);
            }
        });
        DB::statement('alter table trash_entries alter column snapshot type jsonb using snapshot::jsonb');

        DB::table('contacts')->orderBy('id')->lazyById(500)->each(function ($c) {
            DB::table('contacts')->where('id', $c->id)->update([
                'email' => $c->email === null ? null : Crypt::decryptString($c->email),
                'phone' => $c->phone === null ? null : Crypt::decryptString($c->phone),
            ]);
        });
        Schema::table('contacts', function (Blueprint $t) {
            $t->dropColumn(['email_hash', 'phone_hash']);
            $t->string('email')->nullable()->change();
            $t->string('phone')->nullable()->change();
            $t->index('email');
            $t->index('phone');
        });
    }
};
