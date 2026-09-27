<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Account;
use App\Models\LoadBalancer;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\Infrastructure\LoadBalancerChanges;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveLoadBalancer
{
    public function __construct(private readonly LoadBalancerChanges $changes) {}

    /**
     * Add a load balancer on a Caddy server in the account, or change its hostname, health path or website. Its server
     * can't change (the old one would keep serving); delete it and add another instead.
     *
     * @param  array{hostname: string, health_path: string, server_id?: int|string, website_id?: int|string|null}  $data
     */
    public function handle(Account $account, User $actor, array $data, ?LoadBalancer $balancer = null): LoadBalancer
    {
        Gate::forUser($actor)->authorize($balancer === null ? 'create' : 'update', $balancer ?? [LoadBalancer::class, $account]);
        $website = filled($data['website_id'] ?? null) ? Website::query()->where('account_id', $account->id)->find((int) $data['website_id']) : null;
        if (filled($data['website_id'] ?? null) && $website === null) {
            throw ValidationException::withMessages(['website_id' => __('Choose a website in this account.')]);
        }
        $hostname = strtolower(trim($data['hostname']));
        if (LoadBalancer::query()->where('hostname', $hostname)->when($balancer !== null, fn ($query) => $query->whereKeyNot($balancer?->id))->exists()) {
            throw ValidationException::withMessages(['hostname' => __('Another load balancer already serves this hostname.')]);
        }
        if ($balancer === null) {
            $server = Server::query()->where('account_id', $account->id)->find((int) ($data['server_id'] ?? 0));
            if ($server === null || $server->provisioning_status !== Server::STATUS_ACTIVE || ! in_array('caddy', $server->type->installs(), true)) {
                throw ValidationException::withMessages(['server_id' => __('Choose an active server that runs Caddy.')]);
            }
            if ($website !== null && $website->server_id === $server->id) {
                throw ValidationException::withMessages(['server_id' => __('Use a different server from the website’s own.')]);
            }
            $balancer = new LoadBalancer;
            $balancer->forceFill(['account_id' => $account->id, 'created_by' => $actor->id, 'server_id' => $server->id, 'status' => 'pending', 'hostname' => $hostname, 'health_path' => $data['health_path']])->save();
        }

        return $this->changes->apply($balancer, fn (LoadBalancer $locked) => $locked->forceFill(['hostname' => $hostname, 'health_path' => $data['health_path'], 'website_id' => $website?->id]));
    }
}
