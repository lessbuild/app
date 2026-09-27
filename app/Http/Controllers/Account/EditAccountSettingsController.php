<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class EditAccountSettingsController
{
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user): View
    {
        Gate::authorize('update', $account);

        return view('account.settings', ['account' => $account, 'canDelete' => Gate::allows('delete', $account)]);
    }
}
