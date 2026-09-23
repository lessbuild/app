<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Models\Recipe;
use Illuminate\Support\Facades\DB;

class RecipeReportLocks
{
    /**
     * Reserve the SQLite writer before reading the recipe snapshot, then take the recipe row lock.
     *
     * Call inside a transaction before reading state that will be changed.
     */
    public function recipe(int $id): Recipe
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Recipe::query()->whereKey($id)->update(['id' => DB::raw('id')]);
        }

        return Recipe::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }
}
