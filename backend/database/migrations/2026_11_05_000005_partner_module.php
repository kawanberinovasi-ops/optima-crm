<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Partner dipisah dari modul Client (revisi klien 5 Nov 2026):
 * - client_partners.partner_type: jenis partner (Vendor, Media partner, KOL/KOC, Komunitas, ...), hanya untuk status Partner.
 * - Daftar pilihan per modul: "Jenis partner" dan "Bidang partner" baru; nilai segmen yang sifatnya bidang partner
 *   (media, gaya hidup, kuliner, perjalanan) pindah dari Segmen client ke Bidang partner. Data lama tetap menyimpan nilainya.
 */
return new class extends Migration
{
    private const PARTNER_TYPES = ['Vendor', 'Media partner', 'KOL/KOC', 'Komunitas', 'Lainnya'];

    private const PARTNER_FIELDS = ['Media lokal', 'Radio', 'Online', 'Lifestyle', 'Kuliner', 'Travel', 'Event', 'Produksi'];

    public function up(): void
    {
        Schema::table('client_partners', function (Blueprint $table) {
            $table->string('partner_type', 100)->nullable()->after('service_id');
        });

        DB::statement('alter table option_lists drop constraint if exists option_lists_list_check');
        DB::statement("alter table option_lists add constraint option_lists_list_check check (list in ('segments', 'sources', 'interaction_types', 'lost_reasons', 'partner_types', 'partner_fields'))");
        foreach (['partner_types' => self::PARTNER_TYPES, 'partner_fields' => self::PARTNER_FIELDS] as $list => $values) {
            foreach ($values as $i => $value) {
                DB::table('option_lists')->updateOrInsert(['list' => $list, 'value' => $value], ['position' => $i, 'active' => true, 'updated_at' => now()]);
            }
        }
        DB::table('option_lists')->where('list', 'segments')->whereIn('value', self::PARTNER_FIELDS)->delete();
    }

    public function down(): void
    {
        DB::table('option_lists')->whereIn('list', ['partner_types', 'partner_fields'])->delete();
        DB::statement('alter table option_lists drop constraint if exists option_lists_list_check');
        DB::statement("alter table option_lists add constraint option_lists_list_check check (list in ('segments', 'sources', 'interaction_types', 'lost_reasons'))");
        Schema::table('client_partners', fn (Blueprint $table) => $table->dropColumn('partner_type'));
    }
};
