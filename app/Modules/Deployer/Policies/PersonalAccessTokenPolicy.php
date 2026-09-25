<?php

namespace App\Modules\Deployer\Policies;

use App\Modules\Deployer\Models\User;
use Illuminate\Auth\Access\Response;
use Laravel\Sanctum\PersonalAccessToken;

class PersonalAccessTokenPolicy
{
    /**
     * Allow only the current workspace owner to issue API credentials.
     */
    public function create(User $user): bool
    {
        return $user->currentOrganization?->owner?->is($user) ?? false;
    }

    /**
     * Allow rotation only for the token owner in its workspace, concealing unrelated tokens as 404.
     */
    public function rotate(User $user, PersonalAccessToken $token): Response
    {
        return $this->belongsTo($user, $token) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Allow revocation only for the authenticated token owner, including cleanup of legacy or invalid-scope tokens.
     */
    public function delete(User $user, PersonalAccessToken $token): Response
    {
        return $this->isOwnedBy($user, $token) ? Response::allow() : Response::denyAsNotFound();
    }

    private function belongsTo(User $user, PersonalAccessToken $token): bool
    {
        if (! $this->isOwnedBy($user, $token)) {
            return false;
        }

        $scopeClaims = collect($token->abilities)
            ->filter(fn (mixed $ability): bool => is_string($ability)
                && (str_starts_with($ability, 'workspace:') || str_starts_with($ability, 'project:')))
            ->values();

        if ($scopeClaims->isEmpty()) {
            return true;
        }

        $workspaceClaims = $scopeClaims->filter(fn (string $ability): bool => str_starts_with($ability, 'workspace:'));
        $projectClaims = $scopeClaims->filter(fn (string $ability): bool => str_starts_with($ability, 'project:'));

        return $workspaceClaims->count() === 1
            && ctype_digit(substr((string) $workspaceClaims->first(), strlen('workspace:')))
            && (string) $user->current_organization_id === substr((string) $workspaceClaims->first(), strlen('workspace:'))
            && $projectClaims->every(fn (string $ability): bool => ctype_digit(substr($ability, strlen('project:'))));
    }

    private function isOwnedBy(User $user, PersonalAccessToken $token): bool
    {
        return (string) $token->tokenable_id === (string) $user->getKey()
            && $token->tokenable_type === $user->getMorphClass();
    }
}
