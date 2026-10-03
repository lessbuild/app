<?php

declare(strict_types=1);

namespace App\Queries\Recipes;

use App\Models\RecipeReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/** Reports on gallery recipes, as their publishers and their reporters see them. */
final class RecipeReportsQuery
{
    /**
     * Get the reports on an account's recipes, open ones first, then the newest.
     *
     * @param  string  $accountId
     * @return Collection<int, RecipeReport>
     */
    public function forPublisher(string $accountId): Collection
    {
        return RecipeReport::query()->whereHas('recipe', fn ($query) => $query->where('account_id', $accountId))->with(['recipe', 'user', 'resolver'])
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->latest('updated_at')->limit(200)->get();
    }

    /**
     * Get the reports a person filed, newest first.
     *
     * @param  User  $reporter
     * @return Collection<int, RecipeReport>
     */
    public function forReporter(User $reporter): Collection
    {
        return RecipeReport::query()->where('user_id', $reporter->id)->with('recipe')->latest('updated_at')->limit(200)->get();
    }
}
