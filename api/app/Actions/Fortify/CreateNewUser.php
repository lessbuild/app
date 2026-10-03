<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Users\RegisterUser;
use App\Data\Users\RegisterUserData;
use App\Models\User;
use App\Services\Identity\SignUpProtection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/** Fortify adapter: validates registration input and delegates to the Identity domain. */
final class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Create a new CreateNewUser instance.
     *
     * Fortify's registration adapter.
     *
     * @param  RegisterUser  $registerUser  Registers the person and creates their account.
     * @param  SignUpProtection  $protection  Keeps bots and floods off sign-up.
     */
    public function __construct(private readonly RegisterUser $registerUser, private readonly SignUpProtection $protection) {}

    /**
     * Validate the registration form and register the person, with the access invitation the form carried, if any.
     *
     * @param  array<string, string>  $input
     * @return User
     */
    public function create(array $input): User
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
        ])->validate();
        $this->protection->check($input);

        return $this->registerUser->handle(new RegisterUserData(
            $validated['name'], $validated['email'], $validated['password'],
            is_string($input['invite'] ?? null) ? $input['invite'] : null,
            is_string($input['referral'] ?? null) ? $input['referral'] : null,
        ));
    }
}
