<?php

namespace App\Modules\Deployer\Actions\Account;

use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ActivityRecorder;
use App\Modules\Deployer\Services\BrowserSessionManager;

class RevokeSessionAction
{
    public function __construct(
        private readonly BrowserSessionManager $browserSessions,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Revoke one owned browser session while preserving the manager's protection and availability outcomes.
     *
     * @param  User  $user  The account that must own the stored session.
     * @param  string  $sessionId  The route-attested stored-session identifier.
     * @param  string  $currentSessionId  The active browser session that must be retained.
     * @return 'unavailable'|'current'|'revoked'|'missing' The ownership-scoped deletion outcome.
     */
    public function handle(User $user, string $sessionId, string $currentSessionId): string
    {
        $result = $this->browserSessions->revoke($user, $sessionId, $currentSessionId);

        if ($result === 'revoked') {
            $this->activity->recordAccount($user, 'A browser session was logged out.');
        }

        return $result;
    }
}
