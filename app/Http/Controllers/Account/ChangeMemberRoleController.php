<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\ChangeMemberRole;
use App\Enums\AccountRole;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ChangeMemberRoleController
{
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, string $membership, ChangeMemberRole $change): RedirectResponse
    {
        $validated = $request->validate(['role' => ['required', Rule::enum(AccountRole::class)]]);
        $updated = $change->handle($user, $account->memberships()->findOrFail($membership), AccountRole::from($validated['role']));

        return to_route('account.members')->with('status', __(':name is now :role.', ['name' => $updated->user->name, 'role' => $updated->role->label()]));
    }
}
