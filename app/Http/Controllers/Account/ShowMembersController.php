<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use App\Platform\ServiceRegistry;
use App\Queries\Accounts\MembersOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowMembersController
{
    public function __construct(private readonly ServiceRegistry $services) {}

    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, MembersOverviewQuery $query): View
    {

        return view('account.members', ['account' => $account, 'overview' => $query->handle($account, $user), 'services' => $this->services->all()]);
    }
}
