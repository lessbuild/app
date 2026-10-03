<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsAdAccount;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DisconnectAdAccount
{
    /**
     * Stop reading spend from an ad account. Spend already read stays until it's removed from the site.
     *
     * @param  User  $actor
     * @param  AnalyticsAdAccount  $account
     * @return void
     */
    public function handle(User $actor, AnalyticsAdAccount $account): void
    {
        Gate::forUser($actor)->authorize('update', $account->site);
        $account->delete();
    }
}
