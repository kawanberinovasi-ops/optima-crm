<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Catatan tambahan saat deal Lost, terpisah dari alasan Lost yang dipilih dari daftar (sama seperti prototipe). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('lost_note')->nullable()->after('lost_reason');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('lost_note');
        });
    }
};
