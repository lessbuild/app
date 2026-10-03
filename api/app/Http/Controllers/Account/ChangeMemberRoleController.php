<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\ChangeMemberRole;
use App\Enums\AccountRole;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ChangeMemberRoleController
{
    /**
     * Change a member's role from the members page.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $membership
     * @param  ChangeMemberRole  $change
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, string $membership, ChangeMemberRole $change): JsonResponse
    {
        $validated = $request->validate(['role' => ['required', Rule::enum(AccountRole::class)]]);
        $updated = $change->handle($user, $account->memberships()->findOrFail($membership), AccountRole::from($validated['role']));

        return response()->json(['redirect' => route('account.members', [], false), 'message' => __(':name is now :role.', ['name' => $updated->user->name, 'role' => $updated->role->label()])]);
    }
}
