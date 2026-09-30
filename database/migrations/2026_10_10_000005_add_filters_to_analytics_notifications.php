<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the report filters a scheduled CSV export uses, copied from a saved view, and the saved view's name.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('analytics_notifications', function (Blueprint $table): void {
            $table->json('filters')->nullable()->after('threshold');
            $table->string('view_name', 120)->nullable()->after('filters');
        });
    }

    /**
     * Remove the filters.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('analytics_notifications', function (Blueprint $table): void {
            $table->dropColumn(['filters', 'view_name']);
        });
    }
};
