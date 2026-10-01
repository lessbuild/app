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
     * Create a new UpdateUserProfileInformation instance.
     *
     * Fortify's profile adapter.
     *
     * @param  UpdateProfile  $updateProfile  Saves the change.
     */
    public function __construct(private readonly UpdateProfile $updateProfile) {}

    /**
     * Validate the profile form (the email must stay unique, the language must be supported) and save it.
     *
     * @param  User  $user
     * @param  array<string, string|null>  $input
     * @return void
     */
    public function update(User $user, array $input): void
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'locale' => ['nullable', 'string', Rule::in(array_keys((array) config('app.supported_locales', [])))],
        ])->validateWithBag('updateProfileInformation');

        $this->updateProfile->handle($user, new UpdateProfileData($validated['name'], $validated['email'], $validated['locale'] ?? null));
    }
}
