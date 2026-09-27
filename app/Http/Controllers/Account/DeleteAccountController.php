<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\DeleteAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DeleteAccountController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, DeleteAccount $delete): RedirectResponse
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
