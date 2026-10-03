<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\UpdateScimSettings;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UpdateScimSettingsController
{
    /**
     * Create a SCIM token (shown once), change the role new people get, or turn provisioning off.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  UpdateScimSettings  $update
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, UpdateScimSettings $update): JsonResponse
    {
        $data = $request->validate(['change' => ['required', Rule::in(['token', 'role', 'off'])], 'scim_default_role' => ['nullable', 'string', 'max:20']]);
        $token = $update->handle($user, $account, $data['change'], $data['scim_default_role'] ?? null);

        // A new token is returned this once, for pasting into the identity provider.
        return response()->json([
            'redirect' => route('account.security', [], false).'#scim',
            'message' => match ($data['change']) {
                'token' => __('SCIM provisioning is on. Copy the token into your identity provider now.'),
                'off' => __('SCIM provisioning is off. People already added stay members.'),
                default => __('New people will join as :role.', ['role' => $data['scim_default_role']]),
            },
            'token' => $token,
        ]);
    }
}
