<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('load_balancers', function (Blueprint $table): void {
            $table->index(['status', 'updated_at'], 'load_balancers_status_updated_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('load_balancers', function (Blueprint $table): void {
            $table->dropIndex('load_balancers_status_updated_at_index');
        });
    }
};
