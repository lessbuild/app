<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Accounts\Actions\DeleteAccount;
use App\Domain\Accounts\Actions\RenameAccount;
use App\Domain\Accounts\Models\Account;
use App\Domain\Identity\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SettingsController
{
    public function edit(#[CurrentUser] User $user): View
    {
        $account = $this->account($user);
        Gate::authorize('update', $account);

        return view('account.settings', ['account' => $account, 'canDelete' => Gate::allows('delete', $account)]);
    }

    public function update(Request $request, #[CurrentUser] User $user, RenameAccount $rename): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $rename->handle($user, $this->account($user), $validated['name']);

        return to_route('account.settings')->with('status', __('Account renamed.'));
    }

    public function destroy(Request $request, #[CurrentUser] User $user, DeleteAccount $delete): RedirectResponse
    {
        $account = $this->account($user);
        $request->validate(['confirm_name' => ['required', 'string']]);
        if (trim($request->string('confirm_name')->toString()) !== $account->name) {
            throw ValidationException::withMessages(['confirm_name' => __('Type the account name exactly as shown to confirm.')])->errorBag('deleteAccount');
        }

        $delete->handle($user, $account);

        return to_route('dashboard')->with('status', __(':account was deleted.', ['account' => $account->name]));
    }

    private function account(User $user): Account
    {
        return $user->currentAccount ?? abort(404);
    }
}
