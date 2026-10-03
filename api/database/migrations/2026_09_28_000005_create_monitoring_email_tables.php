<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usage alerts (80% and 100% of the monthly event allowance) and the daily issue digest. Each delivery row is a
 * ledger entry, so a period's email goes to a person once however often the command runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_alert_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('period_start', 6);
            $table->unsignedTinyInteger('threshold');
            $table->string('status', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('event_count');
            $table->unsignedBigInteger('event_limit');
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('sending_started_at', 6)->nullable();
            $table->timestamp('sent_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['account_id', 'recipient_id', 'period_start', 'threshold'], 'usage_alert_deliveries_period_unique');
        });

        Schema::create('issue_digest_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled');
            $table->timestamps(6);
            $table->unique(['account_id', 'user_id'], 'issue_digest_preferences_member_unique');
        });

        Schema::create('issue_digest_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('period_start', 6);
            $table->timestamp('period_end', 6);
            $table->string('status', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('resolved_count')->default(0);
            $table->unsignedInteger('open_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('sending_started_at', 6)->nullable();
            $table->timestamp('sent_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['account_id', 'recipient_id', 'period_start', 'period_end'], 'issue_digest_deliveries_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_digest_deliveries');
        Schema::dropIfExists('issue_digest_preferences');
        Schema::dropIfExists('usage_alert_deliveries');
    }
};
