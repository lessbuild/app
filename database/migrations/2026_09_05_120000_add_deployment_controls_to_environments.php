<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->timestamp('deployment_locked_at')->nullable();
            $table->foreignId('deployment_locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deployment_lock_reason', 500)->nullable();
            $table->json('deployment_window_days')->nullable();
            $table->time('deployment_window_start')->nullable();
            $table->time('deployment_window_end')->nullable();
            $table->string('deployment_window_timezone', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deployment_locked_by');
            $table->dropColumn([
                'deployment_locked_at',
                'deployment_lock_reason',
                'deployment_window_days',
                'deployment_window_start',
                'deployment_window_end',
                'deployment_window_timezone',
            ]);
        });
    }
};
