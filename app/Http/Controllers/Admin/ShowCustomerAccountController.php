<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Queries\Admin\CustomersQuery;
use App\Services\Admin\PlatformAdmins;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowCustomerAccountController
{
    /**
     * Show an account for support, recording in the admin trail that it was opened.
     *
     * @param  User  $user
     * @param  string  $account
     * @param  CustomersQuery  $customers
     * @param  PlatformAdmins  $admins
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, string $account, CustomersQuery $customers, PlatformAdmins $admins): View
    {
        $data = $customers->account($account);
        $admins->record($user, 'customer.viewed', "Opened the account {$data['account']->name}.", null, $data['account']);

        return view('admin.customer-account', $data);
    }
}
