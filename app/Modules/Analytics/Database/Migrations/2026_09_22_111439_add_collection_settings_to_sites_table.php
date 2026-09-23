<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('analytics')->table('sites', function (Blueprint $table): void {
            $table->json('excluded_paths')->nullable()->after('domains');
            $table->timestamp('collection_paused_at')->nullable()->after('collection_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('analytics')->table('sites', function (Blueprint $table): void {
            $table->dropColumn(['excluded_paths', 'collection_paused_at']);
        });
    }
};
