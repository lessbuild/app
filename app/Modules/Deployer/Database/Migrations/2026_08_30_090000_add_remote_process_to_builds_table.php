<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->unsignedBigInteger('remote_process_id')->nullable()->after('status');
            $table->string('remote_process_path')->nullable()->after('remote_process_id');
        });
    }

    public function down(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->dropColumn(['remote_process_id', 'remote_process_path']);
        });
    }
};
