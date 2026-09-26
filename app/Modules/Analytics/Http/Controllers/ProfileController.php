<?php

namespace App\Modules\Analytics\Http\Controllers;

use Illuminate\Contracts\View\View;

class ProfileController extends Controller
{
    public function edit(): View
    {
        return view('analytics::account.profile');
    }
}
