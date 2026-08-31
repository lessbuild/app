<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->string('health_status')->default('unknown')->after('health_check_path');
            $table->unsignedSmallInteger('health_failure_count')->default(0)->after('health_status');
            $table->timestamp('health_last_checked_at')->nullable()->after('health_failure_count');
            $table->text('health_last_error')->nullable()->after('health_last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn([
                'health_status',
                'health_failure_count',
                'health_last_checked_at',
                'health_last_error',
            ]);
        });
    }
};
