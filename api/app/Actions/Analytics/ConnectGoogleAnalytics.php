<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Contracts\Analytics\GoogleAnalyticsData;
use App\Models\AnalyticsImport;
use App\Models\AnalyticsSite;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class ConnectGoogleAnalytics
{
    /**
     * Create a new ConnectGoogleAnalytics instance.
     *
     * @param  GoogleAnalyticsData  $google  Swaps Google's code for a refresh token.
     */
    public function __construct(private readonly GoogleAnalyticsData $google) {}

    /**
     * Keep the Google account someone connected for importing into a site, ready for them to choose a property and
     * dates. An earlier connection that wasn't used is replaced.
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @param  string  $code  the code Google sent back
     * @return AnalyticsImport
     */
    public function handle(User $actor, AnalyticsSite $site, string $code): AnalyticsImport
    {
        Gate::forUser($actor)->authorize('update', $site);
        $token = $this->google->exchange($code);
        $site->imports()->where('status', 'connected')->delete();
        $import = new AnalyticsImport;
        $import->forceFill(['site_id' => $site->id, 'created_by' => $actor->id, 'source' => 'ga4', 'refresh_token' => $token, 'status' => 'connected'])->save();

        return $import;
    }
}
