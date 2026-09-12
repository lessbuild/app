<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Services\AccountAuthentication;
use App\Services\ActivityRecorder;
use App\Services\BrowserSessionManager;
use Illuminate\Contracts\Session\Session;

class RevokeOtherSessionsAction
{
    public function __construct(
        private readonly AccountAuthentication $authentication,
        private readonly BrowserSessionManager $browserSessions,
        private readonly Session $session,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Invalidate other authenticated devices, remove other stored sessions, and record the account event.
     *
     * @param  User  $user  The authenticated account whose sessions are being revoked.
     * @param  string  $currentPassword  The validated local-password challenge.
     * @param  string  $currentSessionId  The browser session to retain.
     */
    public function handle(User $user, string $currentPassword, string $currentSessionId): void
    {
        $this->authentication->logoutOtherDevices($currentPassword);
        $this->browserSessions->revokeOthers($user, $currentSessionId);
        $this->session->regenerate(true);
        $this->activity->recordAccount($user, 'Other browser sessions were logged out.');
    }
}
