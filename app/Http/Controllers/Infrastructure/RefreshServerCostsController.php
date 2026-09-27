<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RefreshServerCosts;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RefreshServerCostsController
{
    /**
     * Looks up current prices for the account's servers.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, RefreshServerCosts $refresh): RedirectResponse
    {
        $priced = $refresh->handle($user, $project->account);

        return to_route('infrastructure.costs', $project)->with('status', trans_choice('Prices checked for :count server.|Prices checked for :count servers.', $priced));
    }
}
