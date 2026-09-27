<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\RenameAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RenameAccountController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, RenameAccount $rename): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $rename->handle($user, $this->account($user), $validated['name']);

        return to_route('account.settings')->with('status', __('Account renamed.'));
    }

    private function account(User $user): Account
    {
        return $user->currentAccount ?? abort(404);
    }
}
