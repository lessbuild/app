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
    /**
     * Shows the members page.
     *
     * @param  ServiceRegistry  $services  The services a member's access can be limited to.
     */
    public function __construct(private readonly ServiceRegistry $services) {}

    /**
     * The members page.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  MembersOverviewQuery  $query
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, MembersOverviewQuery $query): View
    {

        return view('account.members', ['account' => $account, 'overview' => $query->handle($account, $user), 'services' => $this->services->all()]);
    }
}
