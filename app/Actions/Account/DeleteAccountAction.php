<?php

namespace App\Actions\Account;

use App\Data\AccountDeletionData;
use App\Exceptions\AccountDeletionOperationException;
use App\Models\Build;
use App\Models\ServerCommandExecution;
use App\Models\User;
use App\Services\AccountAuthentication;
use App\Services\TwoFactorAuthentication;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

class DeleteAccountAction
{
    public function __construct(
        private readonly TwoFactorAuthentication $twoFactor,
        private readonly AccountAuthentication $authentication,
        private readonly DatabaseManager $database,
        private readonly Session $session,
    ) {}

    /**
     * Verify account challenges, enforce workspace safety guards, and delete the account atomically.
     *
     * The checks intentionally remain outside the deletion transaction, matching the existing workflow. The
     * two-factor verification also remains first because recovery-code consumption is part of the current behavior.
     */
    public function handle(User $user, AccountDeletionData $data): void
    {
        if ($user->twoFactorEnabled() && ! $this->twoFactor->verifyUser($user, (string) $data->twoFactorCode)) {
            throw ValidationException::withMessages(['code' => __('The authentication or recovery code is invalid.')])->errorBag('deleteAccount');
        }

        $owned = $user->organizations()->where('organizations.owner_id', $user->id)->get();
        if ($user->organizations()->where('organizations.owner_id', '!=', $user->id)->exists()) {
            throw new AccountDeletionOperationException('Leave every shared workspace before deleting your account.', 422);
        }
        if ($owned->contains(fn ($organization): bool => $organization->members()->whereKeyNot($user->id)->exists())) {
            throw new AccountDeletionOperationException('Remove every teammate before deleting your account.', 422);
        }
        if ($this->hasActiveOperations($owned->pluck('id')->all())) {
            throw new AccountDeletionOperationException('Wait for active deployments and commands to finish before deleting your account.', 409);
        }

        $this->authentication->logout();
        $this->database->transaction(function () use ($owned, $user): void {
            foreach ($owned as $organization) {
                $organization->delete();
            }
            $user->tokens()->delete();
            $user->delete();
        });

        $this->session->invalidate();
        $this->session->regenerateToken();
    }

    /** @param list<int> $organizationIds */
    private function hasActiveOperations(array $organizationIds): bool
    {
        return Build::query()->whereIn('status', Build::ACTIVE_STATUSES)->whereHas('repository', fn ($query) => $query->whereIn('organization_id', $organizationIds))->exists()
            || ServerCommandExecution::query()->active()->whereHas('server', fn ($query) => $query->whereIn('organization_id', $organizationIds))->exists();
    }
}
