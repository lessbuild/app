<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->boolean('health_check_enabled')->default(false)->after('url');
            $table->string('health_check_path')->default('/')->after('health_check_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn(['health_check_enabled', 'health_check_path']);
        });
    }
};
