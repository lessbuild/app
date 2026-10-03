<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Infrastructure part 4a: website health checks run as Monitoring monitors, and alert rules on server metrics. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->foreignId('health_monitor_id')->nullable()->after('health_last_error')->constrained('monitors')->nullOnDelete();
        });

        Schema::create('server_alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('metric', 32);
            $table->string('operator', 3)->default('gte');
            $table->decimal('threshold', 10, 2);
            $table->unsignedTinyInteger('consecutive_breaches')->default(3);
            $table->unsignedTinyInteger('breach_count')->default(0);
            $table->unsignedSmallInteger('cooldown_minutes')->default(60);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_alerting')->default(false);
            $table->timestamp('last_evaluated_at', 6)->nullable();
            $table->timestamp('last_triggered_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['account_id', 'is_enabled'], 'server_alert_rules_account_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_alert_rules');
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('health_monitor_id');
        });
    }
};
