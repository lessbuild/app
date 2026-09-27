<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Models\Account;
use App\Models\User;
use App\Platform\ServiceRegistry;
use App\Queries\Accounts\MembersOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class ShowMembersController
{
    public function __construct(private readonly ServiceRegistry $services) {}

    public function __invoke(#[CurrentUser] User $user, MembersOverviewQuery $query): View
    {
        $account = $this->account($user);
        Gate::authorize('view', $account);

        return view('account.members', ['account' => $account, 'overview' => $query->handle($account, $user), 'services' => $this->services->all()]);
    }

    private function account(User $user): Account
    {
        return $user->currentAccount ?? abort(404);
    }
}
