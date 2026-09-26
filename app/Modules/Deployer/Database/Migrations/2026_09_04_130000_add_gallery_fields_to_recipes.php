<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->boolean('is_published')->default(false)->index();
            $table->string('category', 50)->nullable()->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedBigInteger('install_count')->default(0);
            $table->foreignId('source_recipe_id')
                ->nullable()
                ->constrained('recipes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropForeign(['source_recipe_id']);
            $table->dropColumn([
                'is_published',
                'category',
                'published_at',
                'install_count',
                'source_recipe_id',
            ]);
        });
    }
};
