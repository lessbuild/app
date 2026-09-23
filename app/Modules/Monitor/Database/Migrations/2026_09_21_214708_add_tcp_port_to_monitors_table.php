<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('monitor')->table('monitors', function (Blueprint $table): void {
            $table->unsignedSmallInteger('tcp_port')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection('monitor')->table('monitors')->where('type', 'tcp')->orWhereNotNull('tcp_port')->exists()
            || DB::connection('monitor')->table('monitor_checks')->where('reason', 'like', 'tcp_%')->exists()) {
            throw new RuntimeException('TCP monitoring history exists. Use a forward migration to preserve it.');
        }
        Schema::connection('monitor')->table('monitors', function (Blueprint $table): void {
            $table->dropColumn('tcp_port');
        });
    }
};
