<?php

namespace App\Actions\Account;

use App\Data\TwoFactorLoginResult;
use App\Models\SignInEvent;
use App\Models\User;
use App\Services\AccountAuthentication;
use App\Services\TwoFactorAuthentication;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;

class CompleteTwoFactorLoginAction
{
    public function __construct(
        private readonly TwoFactorAuthentication $twoFactor,
        private readonly AccountAuthentication $authentication,
        private readonly Session $session,
    ) {}

    /**
     * Verify and consume the pending challenge, complete authentication, and clear its session state.
     *
     * Recovery-code verification intentionally occurs before pulling challenge values, preserving the existing
     * behavior when an invalid code is submitted and when recovery-code consumption races another request.
     *
     * @throws ValidationException If no enabled pending account exists or the submitted code is invalid.
     */
    public function handle(string $code): TwoFactorLoginResult
    {
        $user = User::query()->find($this->session->get('two_factor_login_user_id'));
        if (! $user || ! $user->twoFactorEnabled() || ! $this->twoFactor->verifyUser($user, $code)) {
            throw ValidationException::withMessages([
                'code' => __('The authentication or recovery code is invalid.'),
            ]);
        }

        $remember = (bool) $this->session->pull('two_factor_login_remember', false);
        $method = (string) $this->session->pull('two_factor_login_method', SignInEvent::METHOD_PASSWORD);
        $this->session->forget('two_factor_login_user_id');
        $this->authentication->login($user, $remember);
        $this->session->regenerate();

        return new TwoFactorLoginResult($user, $method);
    }
}
