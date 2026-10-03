<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\SetProjectAccess;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UpdateMemberProjectsController
{
    /**
     * Save which projects a member can see and whether they may deploy protected environments.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $membership
     * @param  SetProjectAccess  $setAccess
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, string $membership, SetProjectAccess $setAccess): RedirectResponse
    {
        $validated = $request->validate([
            'project_access' => ['required', Rule::in(['all', 'some'])],
            'projects' => ['array'],
            'projects.*' => ['string'],
            'deploy_protected' => ['sometimes', 'boolean'],
        ]);
        /** @var list<string> $projects */
        $projects = $validated['projects'] ?? [];
        $updated = $setAccess->handle($user, $account->memberships()->findOrFail($membership), $validated['project_access'] === 'all' ? null : $projects, $request->boolean('deploy_protected'));

        return to_route('account.members')->with('status', __('Project access saved for :name.', ['name' => $updated->user->name]));
    }
}
