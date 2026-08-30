<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->foreignId('redeployed_from_build_id')
                ->nullable()
                ->after('commit_message')
                ->constrained('builds')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('redeployed_from_build_id');
        });
    }
};
