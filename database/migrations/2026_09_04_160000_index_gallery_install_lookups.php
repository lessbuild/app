<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'source_recipe_id', 'source_revision_at'],
                'recipes_gallery_installs_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropIndex('recipes_gallery_installs_lookup');
        });
    }
};
