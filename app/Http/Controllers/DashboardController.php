<?php

namespace App\Http\Controllers;

use App\Models\Build;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $attentionWebsites = $user->websites()->where(function ($query): void {
            $query
                ->where('provisioning_status', Website::STATUS_FAILED)
                ->orWhere(function ($query): void {
                    $query
                        ->where('health_check_enabled', true)
                        ->where('health_status', Website::HEALTH_UNHEALTHY);
                });
        });
        $attentionServers = $user->servers()
            ->where('provisioning_status', Server::STATUS_FAILED);
        $attentionRepositories = $user->repositories()
            ->whereHas('latestBuild', fn ($query) => $query->where('status', Build::STATUS_FAILED));

        return view('dashboard', [
            'stats' => [
                'websites' => $user->websites()->count(),
                'servers' => $user->servers()->count(),
                'builds' => $user->builds()->count(),
                'repositories' => $user->repositories()->count(),
            ],
            'attentionCounts' => [
                'websites' => (clone $attentionWebsites)->count(),
                'servers' => (clone $attentionServers)->count(),
                'deployments' => (clone $attentionRepositories)->count(),
            ],
            'attentionWebsites' => $attentionWebsites
                ->with('server')
                ->latest()
                ->limit(5)
                ->get(),
            'attentionServers' => $attentionServers
                ->latest()
                ->limit(5)
                ->get(),
            'attentionRepositories' => $attentionRepositories
                ->with(['latestBuild', 'website'])
                ->latest()
                ->limit(5)
                ->get(),
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
            'recentEvents' => $user->events()
                ->with('parentable')
                ->latest()
                ->limit(8)
                ->get(),
        ]);
    }
}
