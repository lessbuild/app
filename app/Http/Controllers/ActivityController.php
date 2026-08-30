<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('activity.index', [
            'events' => $request->user()
                ->events()
                ->with('parentable')
                ->latest()
                ->paginate(25),
        ]);
    }
}
