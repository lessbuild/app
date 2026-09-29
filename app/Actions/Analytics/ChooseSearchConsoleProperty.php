<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Contracts\Analytics\SearchConsole;
use App\Exceptions\AnalyticsRuleViolation;
use App\Models\AnalyticsSite;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class ChooseSearchConsoleProperty
{
    /**
     * Create a new ChooseSearchConsoleProperty instance.
     *
     * @param  SearchConsole  $searchConsole  Lists the properties the connected Google account can read.
     */
    public function __construct(private readonly SearchConsole $searchConsole) {}

    /**
     * Choose which Search Console property a connected site reads its search terms from.
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @param  string  $property
     * @return void
     */
    public function handle(User $actor, AnalyticsSite $site, string $property): void
    {
        Gate::forUser($actor)->authorize('update', $site);
        if ($site->search_console_token === null || ! in_array($property, $this->searchConsole->properties($site->search_console_token), true)) {
            throw AnalyticsRuleViolation::searchConsolePropertyUnavailable();
        }
        $site->forceFill(['search_console_property' => $property])->save();
    }
}
