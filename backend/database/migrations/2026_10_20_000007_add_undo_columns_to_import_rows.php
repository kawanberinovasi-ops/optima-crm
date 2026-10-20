<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Supaya import bisa dibatalkan (FR-IMP-04): catat apakah baris membuat atau memperbarui data, dan isi sebelumnya. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->string('action', 20)->nullable()->after('entity_id');
            $table->jsonb('before')->nullable()->after('action');
        });
        Schema::table('imports', function (Blueprint $table) {
            $table->foreignId('undone_by')->nullable()->after('undone_at')->constrained('users')->nullOnDelete();
            $table->boolean('skipped_example')->default(false)->after('error_rows');
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('undone_by');
            $table->dropColumn('skipped_example');
        });
        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropColumn(['action', 'before']);
        });
    }
};
