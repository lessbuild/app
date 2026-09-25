<?php

namespace App\Modules\Deployer\Actions\Automation;

use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Entitlements;
use Laravel\Sanctum\NewAccessToken;

class CreatePersonalAccessTokenAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Create an entitled personal access token with normalized abilities and an explicit expiry.
     *
     * @param  User  $actor  Account that owns the token.
     * @param  array{name: string, abilities: list<string>, expires_in_days: int|string, project_ids?: list<int|string>}  $attributes  Validated token attributes.
     * @return NewAccessToken The one-time plaintext token result.
     */
    public function handle(User $actor, array $attributes, ?Organization $organization = null): NewAccessToken
    {
        $organization ??= $actor->currentOrganization;
        $this->entitlements->enforce($organization ?? $actor, 'api');
        abort_unless($organization !== null, 403, 'An active workspace is required to create an API token.');

        $scopeAbilities = ['workspace:'.$organization->getKey()];
        foreach ($attributes['project_ids'] ?? [] as $projectId) {
            $scopeAbilities[] = 'project:'.(int) $projectId;
        }

        return $actor->createToken(
            $attributes['name'],
            array_values(array_unique([...$attributes['abilities'], ...$scopeAbilities])),
            now()->addDays($attributes['expires_in_days']),
        );
    }
}
