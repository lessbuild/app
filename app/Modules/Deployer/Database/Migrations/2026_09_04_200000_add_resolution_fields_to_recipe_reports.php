<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_reports', function (Blueprint $table): void {
            $table->timestamp('resolved_at')->nullable()->after('details')->index();
            $table->text('resolution_note')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_reports', function (Blueprint $table): void {
            $table->dropIndex(['resolved_at']);
        });
        Schema::table('recipe_reports', function (Blueprint $table): void {
            $table->dropColumn(['resolved_at', 'resolution_note']);
        });
    }
};
