<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->longText('recipe_snapshot')->nullable()->after('type');
        });

        DB::table('servers')
            ->select('id')
            ->orderBy('id')
            ->each(function (object $server): void {
                $recipes = DB::table('recipe_server')
                    ->join('recipes', 'recipes.id', '=', 'recipe_server.recipe_id')
                    ->where('recipe_server.server_id', $server->id)
                    ->orderBy('recipe_server.position')
                    ->get(['recipes.name', 'recipes.description', 'recipes.script'])
                    ->map(fn (object $recipe): array => [
                        'name' => $recipe->name,
                        'description' => $recipe->description,
                        'script' => Crypt::decryptString($recipe->script),
                    ])
                    ->values()
                    ->all();

                DB::table('servers')->where('id', $server->id)->update([
                    'recipe_snapshot' => Crypt::encryptString(json_encode($recipes, JSON_THROW_ON_ERROR)),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('recipe_snapshot');
        });
    }
};
