<?php

declare(strict_types=1);

namespace App\Auth\Fortify;

use App\Domain\Identity\Actions\UpdateProfile;
use App\Domain\Identity\Data\UpdateProfileData;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

final class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    public function __construct(private readonly UpdateProfile $updateProfile) {}

    /** @param array<string, string> $input */
    public function update(User $user, array $input): void
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
        ])->validateWithBag('updateProfileInformation');

        $this->updateProfile->handle($user, new UpdateProfileData($validated['name'], $validated['email']));
    }
}
