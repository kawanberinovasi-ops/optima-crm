<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Interaksi dan follow-up (SRS 4.2, FR-INT). Catatan tidak bisa dihapus, hanya dibatalkan dengan alasan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interactions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('client_partner_id')->constrained()->restrictOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('occurred_on');
            $table->string('type');
            $table->text('result');
            $table->text('next_action')->nullable();
            $table->date('followup_on')->nullable();
            $table->timestamp('followup_done_at')->nullable();
            $table->foreignId('followup_done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['client_partner_id', 'occurred_on']);
            $table->index('user_id');
        });
        DB::statement("alter table interactions add constraint interactions_cancel_needs_reason check (cancelled_at is null or cancel_reason is not null)");
        // Follow-up yang masih terbuka, untuk My Work dan email ringkasan 08.00.
        DB::statement('create index interactions_open_followups on interactions (followup_on) where followup_on is not null and followup_done_at is null and cancelled_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('interactions');
    }
};
