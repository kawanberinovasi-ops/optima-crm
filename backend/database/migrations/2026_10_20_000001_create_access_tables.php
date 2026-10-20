<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hak akses per department (SRS bagian 3), pengecualian per orang (FR-USR-05),
 * dan daftar pilihan yang diatur admin (FR-USR-06).
 */
return new class extends Migration
{
    public function up(): void
    {
        // entity data: lead, client, community, kol, media, interaksi, deal -> level N/R/C/M
        // entity nilai (nilai deal): Ya, Ya*, Lihat*, Tidak
        // entity rate (rate card KOL/KOC): Ya, M, R*, Tidak (Ya dan M boleh lihat, R* hanya Kepala Department dan Koordinator)
        Schema::create('access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('entity', 20);
            $table->string('level', 10);
            $table->timestamps();
            $table->unique(['department_id', 'entity']);
        });

        Schema::create('access_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('entity', 20);
            $table->string('level', 10);
            $table->string('reason')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'entity']);
        });

        $check = "(entity in ('lead','client','community','kol','media','interaksi','deal') and level in ('N','R','C','M'))"
            ." or (entity = 'nilai' and level in ('Ya','Ya*','Lihat*','Tidak'))"
            ." or (entity = 'rate' and level in ('Ya','M','R*','Tidak'))";
        DB::statement("alter table access_grants add constraint access_grants_level_check check ($check)");
        DB::statement("alter table access_overrides add constraint access_overrides_level_check check ($check)");

        Schema::create('option_lists', function (Blueprint $table) {
            $table->id();
            $table->enum('list', ['segments', 'sources', 'interaction_types', 'lost_reasons']);
            $table->string('value');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['list', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_lists');
        Schema::dropIfExists('access_overrides');
        Schema::dropIfExists('access_grants');
    }
};
