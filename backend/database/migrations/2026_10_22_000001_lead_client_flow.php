<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alur bisnis Lead/Client (perubahan 22 Okt 2026):
 * - Lead dan Client adalah status relasi. KOL, Community, Media, dan Digital Campaign adalah layanan dengan pemetaan
 *   ke divisi yang bisa diatur admin.
 * - Akses mengikuti divisi pemilik; department dengan sees_all (Executive) melihat lintas divisi.
 * - Serah terima antar divisi dicatat di tabel handovers.
 * - Satu pengguna bisa punya email alternatif (satu identitas).
 * Data lama dipertahankan: id, kontak, interaksi, deal, dan riwayat tidak berubah.
 */
return new class extends Migration
{
    private const SERVICES = [
        // layanan => divisi awal (KOL dan Community: Digital Campaign sebagai pemetaan sementara)
        'KOL' => 'Digital Campaign',
        'Community' => 'Digital Campaign',
        'Media' => 'Creative & Media',
        'Digital Campaign' => 'Digital Campaign',
    ];

    private const OLD_CATEGORY_TO_SERVICE = ['KOL/KOC' => 'KOL', 'Community' => 'Community', 'Media' => 'Media'];

    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->boolean('sees_all')->default(false)->after('name');
        });
        DB::table('departments')->where('name', 'Executive')->update(['sees_all' => true]);

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        $position = 0;
        foreach (self::SERVICES as $name => $dept) {
            DB::table('services')->insert([
                'name' => $name, 'department_id' => DB::table('departments')->where('name', $dept)->value('id'),
                'position' => $position++, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::table('client_partners', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('category')->constrained()->nullOnDelete();
        });

        // Kategori lama KOL/KOC, Community, Media jadi layanan. Status relasi: Client kalau pernah Won, selain itu Lead.
        DB::statement('alter table client_partners drop constraint if exists client_partners_category_check');
        foreach (self::OLD_CATEGORY_TO_SERVICE as $old => $service) {
            DB::table('client_partners')->where('category', $old)->update([
                'service_id' => DB::table('services')->where('name', $service)->value('id'),
                'category' => 'Lead',
            ]);
        }
        $wonFirst = DB::table('deals')->where('status', 'Won')->groupBy('client_partner_id')->selectRaw('client_partner_id, min(closed_at) as first_won');
        foreach ($wonFirst->get() as $row) {
            DB::table('client_partners')->where('id', $row->client_partner_id)->where('category', 'Lead')
                ->update(['category' => 'Client', 'converted_at' => $row->first_won]);
        }
        DB::statement("alter table client_partners add constraint client_partners_category_check check (category in ('Lead', 'Client'))");

        // Matriks hak akses: kolom Community, KOL/KOC, Media tidak dipakai lagi (sekarang layanan).
        foreach (['access_grants', 'access_overrides'] as $table) {
            DB::table($table)->whereIn('entity', ['community', 'kol', 'media'])->delete();
            DB::statement("alter table {$table} drop constraint if exists {$table}_level_check");
            DB::statement("alter table {$table} add constraint {$table}_level_check check ("
                ."(entity in ('lead','client','interaksi','deal') and level in ('N','R','C','M'))"
                ." or (entity = 'nilai' and level in ('Ya','Ya*','Lihat*','Tidak'))"
                ." or (entity = 'rate' and level in ('Ya','M','R*','Tidak')))");
        }
        // Siapa pun yang punya hak input bisa menerima Lead langsung: department yang boleh mencatat interaksi minimal C untuk Lead.
        $inputDepts = DB::table('access_grants')->where('entity', 'interaksi')->whereIn('level', ['C', 'M'])->pluck('department_id');
        DB::table('access_grants')->where('entity', 'lead')->whereIn('level', ['N', 'R'])->whereIn('department_id', $inputDepts)->update(['level' => 'C']);

        Schema::create('user_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email')->unique();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason');
            $table->unsignedInteger('deals_moved')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['client_partner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handovers');
        Schema::dropIfExists('user_emails');
        DB::statement('alter table client_partners drop constraint if exists client_partners_category_check');
        foreach (array_flip(self::OLD_CATEGORY_TO_SERVICE) as $service => $old) {
            DB::table('client_partners')->whereIn('service_id', DB::table('services')->where('name', $service)->select('id'))->update(['category' => $old]);
        }
        DB::statement("alter table client_partners add constraint client_partners_category_check check (category in ('Lead', 'Client', 'Community', 'KOL/KOC', 'Media'))");
        Schema::table('client_partners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
        });
        Schema::dropIfExists('services');
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('sees_all');
        });
    }
};
