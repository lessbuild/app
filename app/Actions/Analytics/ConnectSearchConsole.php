<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Contracts\Analytics\SearchConsole;
use App\Models\AnalyticsSite;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class ConnectSearchConsole
{
    /**
     * Create a new ConnectSearchConsole instance.
     *
     * @param  SearchConsole  $searchConsole  Swaps Google's code for a token and lists properties.
     */
    public function __construct(private readonly SearchConsole $searchConsole) {}

    /**
     * Link a site to Google Search Console with the code Google sent back, and pick the property matching one of the
     * site's hostnames when there is one.
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @param  string  $code
     * @return AnalyticsSite
     *
     * @throws RuntimeException when Google refuses
     */
    public function handle(User $actor, AnalyticsSite $site, string $code): AnalyticsSite
    {
        Gate::forUser($actor)->authorize('update', $site);

        $token = $this->searchConsole->exchange($code);
        $properties = $this->searchConsole->properties($token);
        $match = null;
        foreach ($site->domains as $domain) {
            foreach (['sc-domain:'.$domain, 'https://'.$domain.'/', 'http://'.$domain.'/'] as $candidate) {
                if ($match === null && in_array($candidate, $properties, true)) {
                    $match = $candidate;
                }
            }
        }
        $site->forceFill(['search_console_token' => $token, 'search_console_property' => $match, 'search_console_connected_at' => now()])->save();

        return $site;
    }
}
