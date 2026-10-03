<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add component groups to status pages, and the monthly uptime report email.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('status_page_components', function (Blueprint $table): void {
            $table->string('group_name', 80)->nullable();
        });
        Schema::table('status_pages', function (Blueprint $table): void {
            $table->boolean('monthly_report')->default(false);
            $table->string('last_monthly_report', 7)->nullable();
        });
    }

    /**
     * Remove them.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('status_page_components', function (Blueprint $table): void {
            $table->dropColumn('group_name');
        });
        Schema::table('status_pages', function (Blueprint $table): void {
            $table->dropColumn(['monthly_report', 'last_monthly_report']);
        });
    }
};
