<?php

namespace App\Actions\Account;

use App\Data\PasswordUpdateData;
use App\Models\User;
use App\Services\AccountAuthentication;
use App\Services\ActivityRecorder;
use App\Services\BrowserSessionManager;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Session\Session;

class UpdatePasswordAction
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly AccountAuthentication $authentication,
        private readonly BrowserSessionManager $browserSessions,
        private readonly Session $session,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Persist a local password, invalidate other devices and record the account security event.
     *
     * @param  User  $user  The authenticated account being updated.
     * @param  PasswordUpdateData  $data  The validated replacement password.
     * @param  string  $currentSessionId  The browser session to retain when invalidating other devices.
     */
    public function handle(User $user, PasswordUpdateData $data, string $currentSessionId): void
    {
        $user->update([
            'password' => $this->hasher->make($data->password),
            'password_set_at' => now(),
        ]);

        $this->authentication->logoutOtherDevices($data->password);
        $this->browserSessions->revokeOthers($user, $currentSessionId);

        $this->session->regenerate(true);
        $this->activity->recordAccount($user, 'Account password was changed.');
    }
}
