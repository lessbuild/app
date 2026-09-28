<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Queries\Admin\CustomersQuery;
use App\Services\Admin\PlatformAdmins;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowCustomerUserController
{
    /**
     * Show a person for support, recording in the admin trail that they were looked up.
     *
     * @param  User  $user
     * @param  string  $person
     * @param  CustomersQuery  $customers
     * @param  PlatformAdmins  $admins
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, string $person, CustomersQuery $customers, PlatformAdmins $admins): View
    {
        $data = $customers->user($person);
        $admins->record($user, 'customer.viewed', "Opened {$data['user']->email}.", $data['user']);

        return view('admin.customer-user', $data);
    }
}
