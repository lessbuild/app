<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\RemoveSshKey;
use App\Models\User;
use App\Models\UserSshKey;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteSshKeyController
{
    /**
     * Remove one of your SSH keys and return to your security settings. Other people's keys are a 404.
     *
     * @param  User  $user
     * @param  int  $key
     * @param  RemoveSshKey  $remove
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, int $key, RemoveSshKey $remove): JsonResponse
    {
        $remove->handle($user, UserSshKey::query()->where('user_id', $user->id)->findOrFail($key));

        return response()->json(['redirect' => route('settings.security', [], false), 'message' => __('SSH key removed.')]);
    }
}
