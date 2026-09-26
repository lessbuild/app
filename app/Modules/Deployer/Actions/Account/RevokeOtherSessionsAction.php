<?php

namespace App\Modules\Deployer\Actions\Account;

use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\AccountAuthentication;
use App\Modules\Deployer\Services\ActivityRecorder;
use App\Modules\Deployer\Services\BrowserSessionManager;
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
