<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let backup schedules keep a second copy elsewhere and run monthly restore drills.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('website_backup_schedules', function (Blueprint $table): void {
            $table->foreignId('secondary_destination_id')->nullable()->constrained('backup_destinations')->nullOnDelete();
            $table->boolean('monthly_drill')->default(true);
        });
        Schema::table('website_backups', function (Blueprint $table): void {
            $table->string('secondary_status', 20)->nullable();
        });
    }

    /**
     * Remove them.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('website_backups', function (Blueprint $table): void {
            $table->dropColumn('secondary_status');
        });
        Schema::table('website_backup_schedules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('secondary_destination_id');
            $table->dropColumn('monthly_drill');
        });
    }
};
