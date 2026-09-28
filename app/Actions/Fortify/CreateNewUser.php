<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Users\RegisterUser;
use App\Data\Users\RegisterUserData;
use App\Models\User;
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
     */
    public function __construct(private readonly RegisterUser $registerUser) {}

    /**
     * Validate the registration form and registers the person.
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

        return $this->registerUser->handle(new RegisterUserData($validated['name'], $validated['email'], $validated['password']));
    }
}
