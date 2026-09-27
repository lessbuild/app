<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class EditAccountSettingsController
{
    public function __invoke(#[CurrentUser] User $user): View
    {
        $account = $this->account($user);
        Gate::authorize('update', $account);

        return view('account.settings', ['account' => $account, 'canDelete' => Gate::allows('delete', $account)]);
    }

    private function account(User $user): Account
    {
        return $user->currentAccount ?? abort(404);
    }
}
