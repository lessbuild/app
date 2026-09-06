<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuration_operations', function (Blueprint $table): void {
            $table->unsignedInteger('retry_sequence')->default(0);
            $table->foreignId('retry_of_operation_id')->nullable()->unique()
                ->constrained('configuration_operations')->cascadeOnDelete();
            $table->dropUnique('configuration_operation_identity_unique');
            $table->unique(['configuration_application_id', 'environment_slug', 'kind', 'retry_sequence'], 'configuration_operation_attempt_unique');
        });
    }

    public function down(): void
    {
        // A populated retry history must not be collapsed into the original unique
        // identity: preserve it and require an explicit data migration for rollback.
        if (DB::table('configuration_operations')->whereNotNull('retry_of_operation_id')->exists()) {
            throw new RuntimeException('Configuration retry history must be archived before rolling back this migration.');
        }
        Schema::table('configuration_operations', function (Blueprint $table): void {
            $table->dropForeign(['retry_of_operation_id']);
            $table->dropUnique(['retry_of_operation_id']);
            $table->dropUnique('configuration_operation_attempt_unique');
            $table->dropColumn(['retry_of_operation_id', 'retry_sequence']);
            $table->unique(['configuration_application_id', 'environment_slug', 'kind'], 'configuration_operation_identity_unique');
        });
    }
};
