<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\AddSshKey;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreSshKeyController
{
    /**
     * Add an SSH key to your profile and return to your security settings.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  AddSshKey  $add
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, AddSshKey $add): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'public_key' => ['required', 'string', 'max:5000']]);
        $add->handle($user, $data['name'], $data['public_key']);

        return response()->json(['redirect' => route('settings.security', [], false), 'message' => __('SSH key added.')]);
    }
}
