<?php

namespace App\Actions\Account;

use App\Data\PasswordLoginResult;
use App\Models\SignInEvent;
use App\Models\User;
use App\Services\AccountAuthentication;
use Illuminate\Contracts\Session\Session;

class CompletePasswordLoginAction
{
    public function __construct(
        private readonly AccountAuthentication $authentication,
        private readonly Session $session,
    ) {}

    /**
     * Regenerate the authenticated session and stage an enabled two-factor account for its challenge.
     *
     * Credential verification and rate limiting remain in LoginRequest so Laravel's request-bound lockout
     * behavior is preserved before this post-authentication workflow begins.
     */
    public function handle(User $user, bool $remember = false): PasswordLoginResult
    {
        $this->session->regenerate();
        if ($user->twoFactorEnabled()) {
            $this->session->put([
                'two_factor_login_user_id' => $user->id,
                'two_factor_login_remember' => $remember,
                'two_factor_login_method' => SignInEvent::METHOD_PASSWORD,
            ]);
            $this->authentication->logout();

            return new PasswordLoginResult($user, PasswordLoginResult::TWO_FACTOR_REQUIRED);
        }

        return new PasswordLoginResult($user, PasswordLoginResult::AUTHENTICATED);
    }
}
