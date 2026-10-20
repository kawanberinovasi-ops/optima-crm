<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline deal (SRS 4.3): stage per lini bisnis, satu stage akhir Closing per pipeline,
 * status Open/Won/Lost/On Hold, dan riwayat setiap perpindahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position');
            $table->boolean('is_closing')->default(false);
            $table->timestamps();
            $table->index(['pipeline_id', 'position']);
        });
        DB::statement('create unique index stages_one_closing_per_pipeline on stages (pipeline_id) where is_closing');

        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('service')->nullable();
            $table->foreignId('client_partner_id')->constrained()->restrictOnDelete();
            $table->foreignId('pipeline_id')->constrained()->restrictOnDelete();
            $table->foreignId('stage_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['Open', 'Won', 'Lost', 'On Hold'])->default('Open');
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->decimal('offer_value', 15, 2)->nullable();
            $table->decimal('deal_value', 15, 2)->nullable();
            $table->date('target_closing')->nullable();
            $table->date('closed_at')->nullable();
            $table->string('lost_reason')->nullable();
            $table->string('hold_reason')->nullable();
            $table->date('review_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Filter periode (SRS 4.3): Open/On Hold dari created_at, Won/Lost dari closed_at.
            $table->index(['pipeline_id', 'status', 'stage_id']);
            $table->index('owner_id');
            $table->index('created_at');
            $table->index('closed_at');
        });
        DB::statement("alter table deals add constraint deals_won_needs_value check (status <> 'Won' or (deal_value is not null and closed_at is not null))");
        DB::statement("alter table deals add constraint deals_lost_needs_reason check (status <> 'Lost' or lost_reason is not null)");
        DB::statement("alter table deals add constraint deals_hold_needs_reason check (status <> 'On Hold' or (hold_reason is not null and review_date is not null))");

        Schema::create('deal_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('from_stage_id')->nullable()->constrained('stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->nullable()->constrained('stages')->nullOnDelete();
            $table->string('from_stage_name')->nullable();
            $table->string('to_stage_name')->nullable();
            $table->enum('status', ['Open', 'Won', 'Lost', 'On Hold']);
            $table->text('note')->nullable();
            $table->timestamp('created_at');
            $table->index(['deal_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_histories');
        Schema::dropIfExists('deals');
        Schema::dropIfExists('stages');
        Schema::dropIfExists('pipelines');
    }
};
