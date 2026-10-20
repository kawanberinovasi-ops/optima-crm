<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log (FR-AUD), import (FR-IMP), tampilan tersimpan, notifikasi di aplikasi, dan kode login cadangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Siapa, melakukan apa, pada data apa, kapan, serta isi sebelum dan sesudahnya (SRS 6).
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('summary')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('filename');
            $table->enum('status', ['checked', 'committed', 'undone', 'failed'])->default('checked');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('ok_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->string('sheet', 60);
            $table->unsignedInteger('row_no');
            $table->jsonb('data');
            $table->jsonb('errors')->nullable();
            $table->string('entity_type', 60)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('source_key')->nullable();
            $table->index(['import_id', 'sheet']);
            $table->index(['entity_type', 'source_key']);
        });

        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('page', 40);
            $table->string('name');
            $table->jsonb('filters');
            $table->timestamps();
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->string('link')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'read_at']);
        });

        // Login cadangan dengan kode email (FR-AUT-04).
        Schema::create('login_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('code_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_codes');
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('saved_views');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('imports');
        Schema::dropIfExists('audit_logs');
    }
};
