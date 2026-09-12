<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Database\DatabaseManager;

class ClearSignInHistoryAction
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Delete only the account owner's sign-in records and record the clearing event atomically.
     *
     * @param  User  $user  The authenticated account whose history is being cleared.
     * @return int The number of sign-in records deleted.
     */
    public function handle(User $user): int
    {
        return $this->database->transaction(function () use ($user): int {
            $deleted = $user->signIns()->delete();

            if ($deleted > 0) {
                $this->activity->recordAccount($user, 'Successful sign-in history was cleared.');
            }

            return $deleted;
        });
    }
}
