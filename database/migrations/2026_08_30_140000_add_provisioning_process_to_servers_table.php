<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->unsignedBigInteger('provisioning_process_id')->nullable()->after('provisioning_failure_phase');
            $table->string('provisioning_process_path')->nullable()->after('provisioning_process_id');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn(['provisioning_process_id', 'provisioning_process_path']);
        });
    }
};
