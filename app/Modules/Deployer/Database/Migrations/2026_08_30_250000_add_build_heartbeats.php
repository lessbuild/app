<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->timestamp('last_heartbeat_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->dropColumn('last_heartbeat_at');
        });
    }
};
