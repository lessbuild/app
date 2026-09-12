<?php

namespace App\Actions\Provider;

use App\Models\Organization;
use App\Models\Provider;
use App\Models\User;
use App\Services\Entitlements;

class CreateProviderAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Create a provider connection in the actor's current workspace after applying monitoring entitlement rules.
     *
     * @param  array<string, mixed>  $attributes  Validated and normalized provider settings.
     */
    public function handle(User $actor, array $attributes): Provider
    {
        /** @var Organization $organization */
        $organization = $actor->currentOrganization;
        if ((bool) $attributes['connection_monitoring_enabled']) {
            $this->entitlements->enforce($organization, 'monitoring');
        }

        return $organization->providers()->create(array_merge($attributes, [
            'organization_id' => $organization->id,
            'user_id' => $actor->id,
            'provider' => str((string) $attributes['provider'])->lower()->toString(),
        ]));
    }
}
