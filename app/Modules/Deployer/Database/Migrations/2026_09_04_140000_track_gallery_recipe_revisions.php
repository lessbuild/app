<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->timestamp('gallery_revision_at')->nullable();
            $table->timestamp('source_revision_at')->nullable();
        });

        DB::table('recipes')
            ->where('is_published', true)
            ->whereNotNull('published_at')
            ->update(['gallery_revision_at' => DB::raw('published_at')]);

        DB::table('recipes')
            ->whereNotNull('source_recipe_id')
            ->orderBy('id')
            ->chunkById(100, function ($copies): void {
                foreach ($copies as $copy) {
                    $sourceRevision = DB::table('recipes')
                        ->where('id', $copy->source_recipe_id)
                        ->value('gallery_revision_at');

                    DB::table('recipes')
                        ->where('id', $copy->id)
                        ->update(['source_revision_at' => $sourceRevision]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropColumn(['gallery_revision_at', 'source_revision_at']);
        });
    }
};
