<?php

namespace App\Modules\Deployer\Actions\Automation;

use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Entitlements;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class RotatePersonalAccessTokenAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Issue a one-year replacement before revoking the existing owned token.
     * Legacy tokens without a workspace claim are bound to the actor's active workspace during rotation.
     *
     * The create-then-delete sequence intentionally remains outside a transaction to preserve
     * the existing credential replacement behavior and one-time plaintext response.
     *
     * @param  User  $actor  Account that owns the token.
     * @param  PersonalAccessToken  $token  Already-authorized token being replaced.
     * @return NewAccessToken The replacement plaintext token result.
     */
    public function handle(User $actor, PersonalAccessToken $token): NewAccessToken
    {
        $this->entitlements->enforce($actor, 'api');
        $abilities = $token->abilities;
        $hasWorkspaceScope = collect($abilities)->contains(fn (mixed $ability): bool => is_string($ability) && str_starts_with($ability, 'workspace:'));
        if (! $hasWorkspaceScope && $actor->currentOrganization !== null) {
            $abilities[] = 'workspace:'.$actor->currentOrganization->getKey();
        }

        $replacement = $actor->createToken($token->name, array_values(array_unique($abilities)), now()->addYear());
        $token->delete();

        return $replacement;
    }
}
