<?php

namespace App\Actions\Observability;

use App\Models\AlertDestination;
use App\Models\Organization;
use App\Models\User;
use App\Services\Entitlements;

class CreateAlertDestinationAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Persist an active encrypted alert destination for an entitled workspace.
     *
     * @param  Organization  $organization  Workspace that owns the destination.
     * @param  User  $actor  Account recorded as the destination creator.
     * @param  array<string, mixed>  $attributes  Validated destination fields.
     */
    public function handle(Organization $organization, User $actor, array $attributes): AlertDestination
    {
        $this->entitlements->enforce($organization, 'alerts');

        return $organization->alertDestinations()->create([
            ...$attributes,
            'created_by' => $actor->id,
            'signing_secret' => bin2hex(random_bytes(32)),
            'is_active' => true,
        ]);
    }
}
