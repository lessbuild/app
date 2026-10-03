<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Monday project report: each member's choice to get it, and a ledger so a week's report goes to a person once
 * however often the command runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('weekly_report_emails')->default(true);
        });

        Schema::create('weekly_report_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('period_start', 6);
            $table->string('status', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('project_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('sending_started_at', 6)->nullable();
            $table->timestamp('sent_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['account_id', 'recipient_id', 'period_start'], 'weekly_report_deliveries_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_report_deliveries');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('weekly_report_emails');
        });
    }
};
