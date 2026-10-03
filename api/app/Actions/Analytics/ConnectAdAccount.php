<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Exceptions\AccountRuleViolation;
use App\Models\AnalyticsAdAccount;
use App\Models\AnalyticsSite;
use App\Models\User;
use App\Services\Analytics\AdPlatforms;
use Illuminate\Support\Facades\Gate;

final class ConnectAdAccount
{
    /**
     * Create a new ConnectAdAccount instance.
     *
     * @param  AdPlatforms  $platforms  Lists the accounts the credential can read.
     * @param  SyncAdSpend  $sync  Reads its spend straight away.
     */
    public function __construct(private readonly AdPlatforms $platforms, private readonly SyncAdSpend $sync) {}

    /**
     * Connect one of the ad accounts a credential can read to a site and read its last 30 days of spend.
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @param  string  $platform
     * @param  string  $credential
     * @param  string  $accountId
     * @return AnalyticsAdAccount
     */
    public function handle(User $actor, AnalyticsSite $site, string $platform, string $credential, string $accountId): AnalyticsAdAccount
    {
        Gate::forUser($actor)->authorize('update', $site);
        $match = collect($this->platforms->for($platform)->accounts($credential))->firstWhere('id', $accountId);
        if ($match === null) {
            throw new AccountRuleViolation('account_id', __('Choose one of the ad accounts you can read.'));
        }
        $account = AnalyticsAdAccount::query()->where('site_id', $site->id)->where('platform', $platform)->where('account_id', $accountId)->first() ?? new AnalyticsAdAccount;
        $account->forceFill([
            'site_id' => $site->id, 'platform' => $platform, 'account_id' => $accountId, 'name' => mb_substr($match['name'], 0, 200),
            'source' => AnalyticsAdAccount::PLATFORMS[$platform]['source'], 'credential' => $credential, 'connected_by' => $actor->id, 'error' => null,
        ])->save();
        $this->sync->handle($account);

        return $account;
    }
}
