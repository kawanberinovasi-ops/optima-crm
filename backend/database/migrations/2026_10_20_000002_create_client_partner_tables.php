<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client & Partner Database (SRS 4.2, 6): lima kategori dalam satu tabel, banyak kontak per data.
 * Data tidak pernah dihapus permanen: arsip lewat archived_at, gabung data lewat merged_into_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create extension if not exists pg_trgm');

        Schema::create('client_partners', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('category', ['Lead', 'Client', 'Community', 'KOL/KOC', 'Media']);
            $table->string('segment')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('pic_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('source')->nullable();
            $table->string('city')->nullable();
            $table->text('note')->nullable();
            $table->jsonb('rate_card')->nullable();
            $table->jsonb('client_info')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archive_reason')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('client_partners')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category', 'archived_at']);
            $table->index('pic_id');
            $table->index('department_id');
        });
        DB::statement('create index client_partners_name_trgm on client_partners using gin (name gin_trgm_ops)');

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_partner_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('social')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('email');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('client_partners');
    }
};
