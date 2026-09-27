<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Jobs\Infrastructure\ProvisionWebsite;
use App\Jobs\Infrastructure\RemoveWebsitePlacement;
use App\Models\Account;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class RetryWebsiteProvisioning
{
    /**
     * Starts a failed website's provisioning again.
     *
     * @param  WebsiteServers  $servers  Checks its server can still host websites.
     */
    public function __construct(private readonly WebsiteServers $servers) {}

    /** Set a failed website up again, or retry removing the copy on its previous server. Returns false if neither applies. */
    public function handle(Account $account, User $actor, Website $website): bool
    {
        Gate::forUser($actor)->authorize('update', $website);

        return DB::transaction(function () use ($account, $website): bool {
            $locked = Website::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($website->id);
            if ($locked->provisioning_status === Website::STATUS_FAILED) {
                $this->servers->handle($account, (int) $locked->server_id);
                $locked->forceFill(['provisioning_token' => (string) Str::uuid(), 'setup_stage' => 0, 'provisioning_status' => Website::STATUS_QUEUED, 'provisioning_error' => null, 'provisioned_at' => null])->save();
                $locked->logs()->where('type', 'provisioning')->delete();
                ProvisionWebsite::dispatch($locked->id, (string) $locked->provisioning_token)->afterCommit();

                return true;
            }
            if ($locked->previous_server_id !== null && $locked->placement_cleanup_error !== null) {
                $locked->forceFill(['placement_cleanup_error' => null])->save();
                RemoveWebsitePlacement::dispatch($locked->id, (int) $locked->previous_server_id, $locked->deployment_slug)->afterCommit();

                return true;
            }

            return false;
        });
    }
}
