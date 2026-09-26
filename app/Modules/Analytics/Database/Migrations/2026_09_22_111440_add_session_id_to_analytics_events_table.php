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
        Schema::connection('analytics')->table('analytics_events', function (Blueprint $table): void {
            $table->string('session_id', 64)->nullable()->after('visitor_hash');
            $table->index(['site_id', 'session_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('analytics')->table('analytics_events', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'session_id', 'occurred_at']);
            $table->dropColumn('session_id');
        });
    }
};
