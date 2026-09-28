<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Queries\Admin\CustomersQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowCustomersController
{
    /**
     * Search people and accounts.
     *
     * @param  Request  $request
     * @param  CustomersQuery  $customers
     * @return View
     */
    public function __invoke(Request $request, CustomersQuery $customers): View
    {
        $term = $request->string('q')->limit(200, '')->toString();

        return view('admin.customers', ['term' => $term, ...$customers->search($term)]);
    }
}
