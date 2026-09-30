<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Contracts\Infrastructure\SnapshotsServers;
use App\Exceptions\AccountRuleViolation;
use App\Jobs\Infrastructure\TakeServerSnapshot;
use App\Models\Server;
use App\Models\User;
use App\Services\Infrastructure\ServerProviderResolver;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class UpdateServerSnapshots
{
    /**
     * Create a new UpdateServerSnapshots instance.
     *
     * @param  ServerProviderResolver  $providers  Checks the provider can snapshot.
     */
    public function __construct(private readonly ServerProviderResolver $providers) {}

    /**
     * Turn snapshots before risky changes on or off, or take one now. Only servers at providers that can snapshot.
     *
     * @param  User  $actor
     * @param  Server  $server
     * @param  bool|null  $enabled  the setting, or null to take a snapshot now
     * @return void
     */
    public function handle(User $actor, Server $server, ?bool $enabled): void
    {
        Gate::forUser($actor)->authorize('update', $server);
        $server->loadMissing('provider');
        try {
            $supported = $server->provider !== null && $this->providers->resolve($server->provider) instanceof SnapshotsServers;
        } catch (Throwable) {
            $supported = false;
        }
        if (! $supported || $server->identifier === null) {
            throw new AccountRuleViolation('snapshot_before_changes', __('Snapshots need a server created at DigitalOcean, Hetzner, Vultr, Linode or Lightsail.'));
        }
        if ($enabled === null) {
            TakeServerSnapshot::dispatch($server->id)->afterCommit();

            return;
        }
        $server->forceFill(['snapshot_before_changes' => $enabled])->save();
    }
}
