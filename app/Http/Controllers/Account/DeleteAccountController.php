<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\DeleteAccount;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DeleteAccountController
{
    /**
     * Deletes the account once the person has typed its name exactly, after a recent password confirmation.
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, DeleteAccount $delete): RedirectResponse
    {
        $request->validate(['confirm_name' => ['required', 'string']]);
        if (trim($request->string('confirm_name')->toString()) !== $account->name) {
            throw ValidationException::withMessages(['confirm_name' => __('Type the account name exactly as shown to confirm.')])->errorBag('deleteAccount');
        }

        $delete->handle($user, $account);

        return to_route('dashboard')->with('status', __(':account was deleted.', ['account' => $account->name]));
    }
}
