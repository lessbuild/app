<?php

namespace App\Actions\LoadBalancer;

use App\Exceptions\LoadBalancerOperationException;
use App\Models\Environment;
use App\Models\LoadBalancer;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;

class CreateLoadBalancerAction
{
    /**
     * Persist a load balancer after rejecting placement on the environment's application server.
     *
     * @param  array<string, mixed>  $attributes  Validated hostname and health-path attributes.
     *
     * @throws LoadBalancerOperationException If the selected server also hosts the environment.
     */
    public function handle(
        Organization $organization,
        User $actor,
        Environment $environment,
        Server $server,
        array $attributes,
    ): LoadBalancer {
        if ((int) $environment->server_id === (int) $server->id) {
            throw new LoadBalancerOperationException('Use a dedicated server for the load balancer.');
        }

        return $organization->loadBalancers()->create([
            ...$attributes,
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'created_by' => $actor->id,
        ]);
    }
}
