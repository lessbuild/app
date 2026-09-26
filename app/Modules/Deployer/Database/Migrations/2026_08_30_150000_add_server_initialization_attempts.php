<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->uuid('initialization_token')->nullable()->after('provisioning_token');
        });

        DB::table('servers')
            ->whereIn('provisioning_status', ['queued', 'waiting_for_ip'])
            ->update(['initialization_token' => DB::raw('provisioning_token')]);
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('initialization_token');
        });
    }
};
