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
        Schema::connection('monitor')->table('issues', function (Blueprint $table): void {
            $table->index(['application_id', 'first_seen_at'], 'issues_application_first_seen_index');
            $table->index(['application_id', 'resolved_at'], 'issues_application_resolved_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('monitor')->table('issues', function (Blueprint $table): void {
            $table->dropIndex('issues_application_first_seen_index');
            $table->dropIndex('issues_application_resolved_index');
        });
    }
};
