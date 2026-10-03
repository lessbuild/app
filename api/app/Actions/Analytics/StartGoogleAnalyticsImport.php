<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Contracts\Analytics\GoogleAnalyticsData;
use App\Exceptions\AccountRuleViolation;
use App\Jobs\Analytics\ImportGoogleAnalytics;
use App\Models\AnalyticsImport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

final class StartGoogleAnalyticsImport
{
    /**
     * Create a new StartGoogleAnalyticsImport instance.
     *
     * @param  GoogleAnalyticsData  $google  Lists the properties the connection can read.
     */
    public function __construct(private readonly GoogleAnalyticsData $google) {}

    /**
     * Queue the import of one of the connected account's properties for a range of dates.
     *
     * @param  User  $actor
     * @param  AnalyticsImport  $import  a connected import
     * @param  string  $property  the GA4 property ID
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return void
     */
    public function handle(User $actor, AnalyticsImport $import, string $property, CarbonImmutable $from, CarbonImmutable $until): void
    {
        Gate::forUser($actor)->authorize('update', $import->site);
        if ($import->status !== 'connected' || $import->refresh_token === null) {
            throw new AccountRuleViolation('property', __('Connect Google Analytics again to start another import.'));
        }
        $chosen = collect($this->google->properties($import->refresh_token))->firstWhere('id', $property);
        if ($chosen === null) {
            throw new AccountRuleViolation('property', __('That Google Analytics property isn’t available to the connected account.'));
        }
        if ($until->lt($from)) {
            [$from, $until] = [$until, $from];
        }
        $import->forceFill(['property' => $property, 'property_name' => $chosen['name'], 'from_date' => $from->toDateString(), 'until_date' => $until->min(CarbonImmutable::yesterday())->toDateString(), 'status' => 'queued'])->save();
        ImportGoogleAnalytics::dispatch($import->id);
    }
}
