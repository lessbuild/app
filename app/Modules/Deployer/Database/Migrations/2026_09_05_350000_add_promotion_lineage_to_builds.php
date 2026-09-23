<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->foreignId('promoted_from_build_id')->nullable()->constrained('builds')->nullOnDelete();
            $table->text('promotion_note')->nullable();
            $table->index(['promoted_from_build_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->dropIndex(['promoted_from_build_id', 'status']);
            $table->dropConstrainedForeignId('promoted_from_build_id');
            $table->dropColumn('promotion_note');
        });
    }
};
