<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->boolean('automatic_rollback')->default(false)->after('requires_deployment_approval');
        });

        Schema::table('builds', function (Blueprint $table): void {
            $table->json('risk_assessment')->nullable()->after('environment_payload');
            $table->foreignId('automatic_rollback_build_id')->nullable()->after('rolled_back_from_build_id')->constrained('builds')->nullOnDelete();
        });

        Schema::table('status_incidents', function (Blueprint $table): void {
            $table->text('root_cause')->nullable()->after('message');
            $table->text('remediation')->nullable()->after('root_cause');
            $table->text('follow_up')->nullable()->after('remediation');
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->decimal('monthly_infrastructure_budget', 10, 2)->nullable()->after('session_idle_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('monthly_infrastructure_budget'));
        Schema::table('status_incidents', fn (Blueprint $table) => $table->dropColumn(['root_cause', 'remediation', 'follow_up']));
        Schema::table('builds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('automatic_rollback_build_id');
            $table->dropColumn('risk_assessment');
        });
        Schema::table('environments', fn (Blueprint $table) => $table->dropColumn('automatic_rollback'));
    }
};
