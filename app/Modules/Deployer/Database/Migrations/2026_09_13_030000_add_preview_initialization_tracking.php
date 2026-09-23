<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preview_deployments', function (Blueprint $table): void {
            $table->string('initialization_status', 24)->default('not_configured');
            $table->unsignedInteger('initialization_attempts')->default(0);
            $table->foreignId('initialization_build_id')->nullable()->constrained('builds')->nullOnDelete();
            $table->text('initialization_error')->nullable();
            $table->timestamp('initialization_completed_at')->nullable();
            $table->index(['initialization_status', 'initialization_build_id']);
        });
    }

    public function down(): void
    {
        Schema::table('preview_deployments', function (Blueprint $table): void {
            $table->dropIndex(['initialization_status', 'initialization_build_id']);
            $table->dropForeign(['initialization_build_id']);
            $table->dropColumn([
                'initialization_status',
                'initialization_attempts',
                'initialization_build_id',
                'initialization_error',
                'initialization_completed_at',
            ]);
        });
    }
};
