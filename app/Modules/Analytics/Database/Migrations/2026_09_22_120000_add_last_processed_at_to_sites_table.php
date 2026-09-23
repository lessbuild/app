<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->table('sites', function (Blueprint $table): void {
            $table->timestamp('last_processed_at')->nullable()->after('last_event_at');
            $table->index(['workspace_id', 'last_processed_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->table('sites', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'last_processed_at']);
            $table->dropColumn('last_processed_at');
        });
    }
};
