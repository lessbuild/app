<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Accounts\Actions\SwitchAccount;
use App\Domain\Accounts\Models\Account;
use App\Domain\Identity\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class SwitchAccountController
{
    public function __invoke(#[CurrentUser] User $user, Account $account, SwitchAccount $switch): RedirectResponse
    {
        abort_unless($user->can('view', $account), 404);
        $switch->handle($user, $account);

        return to_route('dashboard');
    }
}
