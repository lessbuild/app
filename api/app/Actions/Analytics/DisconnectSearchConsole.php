<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsSite;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DisconnectSearchConsole
{
    /**
     * Unlink a site from Google Search Console, forgetting its token.
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @return void
     */
    public function handle(User $actor, AnalyticsSite $site): void
    {
        Gate::forUser($actor)->authorize('update', $site);
        $site->forceFill(['search_console_token' => null, 'search_console_property' => null, 'search_console_connected_at' => null])->save();
    }
}
