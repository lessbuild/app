<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Users\ChangePassword;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

final class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * Fortify's password-change adapter.
     *
     * @param  ChangePassword  $changePassword  Sets the new password.
     */
    public function __construct(private readonly ChangePassword $changePassword) {}

    /**
     * Validates the new password, and the current one when the person has one (people who signed up through a provider
     * don't), then changes it.
     *
     * @param  array<string, string>  $input
     */
    public function update(User $user, array $input): void
    {
        $rules = ['password' => $this->passwordRules()];
        // Accounts created through social sign-in have no password yet, so there is nothing to confirm.
        if ($user->password !== null) {
            $rules['current_password'] = ['required', 'string', 'current_password:web'];
        }
        $validated = Validator::make($input, $rules, [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        $this->changePassword->handle($user, $validated['password']);
    }
}
