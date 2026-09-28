<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Users\UpdateProfile;
use App\Data\Users\UpdateProfileData;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

final class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Fortify's profile adapter.
     *
     * @param  UpdateProfile  $updateProfile  Saves the change.
     */
    public function __construct(private readonly UpdateProfile $updateProfile) {}

    /**
     * Validates the profile form (the email must stay unique) and saves it.
     *
     * @param  User  $user
     * @param  array<string, string>  $input
     * @return void
     */
    public function update(User $user, array $input): void
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
        ])->validateWithBag('updateProfileInformation');

        $this->updateProfile->handle($user, new UpdateProfileData($validated['name'], $validated['email']));
    }
}
