<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('recipes')
            ->select(['id', 'script'])
            ->orderBy('id')
            ->each(function (object $recipe): void {
                DB::table('recipes')->where('id', $recipe->id)->update([
                    'script' => Crypt::encryptString($recipe->script),
                ]);
            });
    }

    public function down(): void
    {
        DB::table('recipes')
            ->select(['id', 'script'])
            ->orderBy('id')
            ->each(function (object $recipe): void {
                DB::table('recipes')->where('id', $recipe->id)->update([
                    'script' => Crypt::decryptString($recipe->script),
                ]);
            });
    }
};
