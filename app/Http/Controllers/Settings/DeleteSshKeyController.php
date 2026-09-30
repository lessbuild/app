<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\RemoveSshKey;
use App\Models\User;
use App\Models\UserSshKey;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteSshKeyController
{
    /**
     * Remove one of your SSH keys and return to your security settings. Other people's keys are a 404.
     *
     * @param  User  $user
     * @param  int  $key
     * @param  RemoveSshKey  $remove
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, int $key, RemoveSshKey $remove): RedirectResponse
    {
        $remove->handle($user, UserSshKey::query()->where('user_id', $user->id)->findOrFail($key));

        return to_route('settings.security')->with('status', __('SSH key removed.'));
    }
}
