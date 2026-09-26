<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Accounts\Actions\DeleteAccount;
use App\Domain\Accounts\Actions\RemoveMember;
use App\Domain\Accounts\Queries\DepartureQuery;
use App\Domain\Identity\Events\UserDeleted;
use App\Domain\Identity\Events\UserDeleting;
use App\Domain\Identity\Exceptions\DeletionBlocked;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\BrowserSessions;
use Illuminate\Support\Facades\DB;

final class DeleteUser
{
    public function __construct(
        private readonly DepartureQuery $departure,
        private readonly DeleteAccount $deleteAccount,
        private readonly RemoveMember $removeMember,
        private readonly BrowserSessions $sessions,
    ) {}

    /**
     * Permanently delete a user: accounts only they belong to are deleted, shared accounts are left
     * (so those accounts' audit logs record it), and sign-in methods, history and sessions go with the user.
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            // Lock the user so a concurrent invitation acceptance can't slip in between planning and deleting.
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $departure = $this->departure->handle($user);
            if ($departure->blockedBy !== []) {
                throw new DeletionBlocked(__('Make someone else an owner of :accounts first.', ['accounts' => implode(', ', array_map(fn ($account): string => $account->name, $departure->blockedBy))]));
            }

            foreach ($departure->toLeave as $membership) {
                $this->removeMember->handle($user, $membership);
            }
            foreach ($departure->toDelete as $account) {
                $this->deleteAccount->handle($user, $account);
            }

            $user->tokens()->delete();
            if ($this->sessions->available()) {
                $this->sessions->for($user)->delete();
            }
            UserDeleting::dispatch($user);
            $user->delete();
        });

        UserDeleted::dispatch($user->id, $user->email);
    }
}
