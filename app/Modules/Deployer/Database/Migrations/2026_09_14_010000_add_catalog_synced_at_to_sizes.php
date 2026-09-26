<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sizes', function (Blueprint $table): void {
            $table->timestamp('catalog_synced_at')->nullable()->after('price_hourly');
        });
    }

    public function down(): void
    {
        Schema::table('sizes', function (Blueprint $table): void {
            $table->dropColumn('catalog_synced_at');
        });
    }
};
