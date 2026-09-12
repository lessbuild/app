<?php

namespace App\Actions\Account;

use App\Data\ProfileUpdateData;
use App\Data\ProfileUpdateResult;
use App\Models\User;
use App\Services\AccountAuthentication;
use App\Services\ActivityRecorder;
use App\Services\BrowserSessionManager;
use Illuminate\Contracts\Session\Session;
use Throwable;

class UpdateProfileAction
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly BrowserSessionManager $browserSessions,
        private readonly AccountAuthentication $authentication,
        private readonly Session $session,
    ) {}

    /**
     * Persist an account profile, rotate security state for an email change, and attempt verification delivery.
     *
     * @param  User  $user  The authenticated account being updated.
     * @param  ProfileUpdateData  $data  Validated profile fields and conditional password challenge.
     * @param  string  $currentSessionId  The browser session to retain when invalidating other devices.
     */
    public function handle(User $user, ProfileUpdateData $data, string $currentSessionId): ProfileUpdateResult
    {
        $emailChanged = $user->email !== $data->email;
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->fill([
            'name' => $data->name,
            'email' => $data->email,
        ])->save();

        if ($emailChanged && $user->hasLocalPassword()) {
            $this->authentication->logoutOtherDevices((string) $data->currentPassword);
            $this->browserSessions->revokeOthers($user, $currentSessionId);
            $this->session->regenerate(true);
        }

        $this->activity->recordAccount(
            $user,
            $emailChanged
                ? 'Account email address was changed and requires verification.'
                : 'Account profile was updated.',
        );

        if (! $emailChanged) {
            return new ProfileUpdateResult(false);
        }

        try {
            $user->sendEmailVerificationNotification();

            return new ProfileUpdateResult(true, true);
        } catch (Throwable $exception) {
            report($exception);

            return new ProfileUpdateResult(true);
        }
    }
}
