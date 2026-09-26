<?php

namespace App\Modules\Deployer\Actions\Account;

use App\Modules\Deployer\Data\PasswordLoginResult;
use App\Modules\Deployer\Models\SignInEvent;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\AccountAuthentication;
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
