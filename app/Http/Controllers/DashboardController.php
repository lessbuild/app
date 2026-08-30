<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('dashboard', [
            'stats' => [
                'websites' => $user->websites()->count(),
                'servers' => $user->servers()->count(),
                'builds' => $user->builds()->count(),
                'repositories' => $user->repositories()->count(),
            ],
            'recentWebsites' => $user->websites()
                ->with('server')
                ->latest()
                ->limit(5)
                ->get(),
            'recentBuilds' => $user->builds()
                ->with('repository.website.server')
                ->latest('builds.created_at')
                ->limit(5)
                ->get(),
        ]);
    }
}
