<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Temporary landing page until the Phase 2 project shell replaces it. */
final class DashboardController
{
    public function __invoke(Request $request): View
    {
        return view('dashboard', ['account' => $request->user()?->currentAccount]);
    }
}
