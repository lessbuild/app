<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Accounts\CreateAccount;
use App\Data\Users\RegisterUserData;
use App\Models\User;
use App\Services\Users\Registration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RegisterUser
{
    /**
     * Create a new RegisterUser instance.
     *
     * Registration gives every new person an account of their own.
     *
     * @param  CreateAccount  $createAccount  Creates that first account with them as owner.
     * @param  Registration  $registration  Decides whether they may sign up while registration is closed.
     */
    public function __construct(private readonly CreateAccount $createAccount, private readonly Registration $registration) {}

    /**
     * Create the user together with a first account they own, so every signed-in user has somewhere to work. While
     * registration is closed they need an access invitation (accepted here) or an invitation to join an account.
     *
     * @param  RegisterUserData  $data
     * @return User
     */
    public function handle(RegisterUserData $data): User
    {
        if (! $this->registration->allows($data->email, $data->accessInvite)) {
            throw ValidationException::withMessages(['email' => __('Sign-up is by invitation for now. Request access and we’ll email you an invitation.')]);
        }

        return DB::transaction(function () use ($data): User {
            $this->registration->accept($data->accessInvite);
            $user = new User;
            $user->forceFill([
                'name' => $data->name,
                'email' => Str::lower(trim($data->email)),
                'password' => $data->password,
            ])->save();

            $this->createAccount->handle($user, __(':name’s account', ['name' => Str::before($data->name, ' ') ?: $data->name]));

            return $user->refresh();
        });
    }
}
