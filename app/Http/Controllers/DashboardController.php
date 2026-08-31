<?php

namespace App\Http\Controllers;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $providers = $user->providers();
        $attentionProviders = (clone $providers)
            ->where('connection_status', Provider::CONNECTION_FAILED);
        $activeDeployments = $user->builds()
            ->whereIn('builds.status', Build::ACTIVE_STATUSES);
        $activeDeploymentCounts = (clone $activeDeployments)
            ->select('builds.status', DB::raw('COUNT(*) as total'))
            ->groupBy('builds.status')
            ->pluck('total', 'builds.status');

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
                'providers' => (clone $attentionProviders)->count(),
            ],
            'providerHealthCounts' => [
                'healthy' => (clone $providers)->where('connection_status', Provider::CONNECTION_HEALTHY)->count(),
                'failed' => (clone $attentionProviders)->count(),
                'unchecked' => (clone $providers)->whereNull('connection_status')->count(),
            ],
            'activeDeploymentCounts' => collect(Build::ACTIVE_STATUSES)
                ->mapWithKeys(fn (string $status): array => [
                    $status => (int) ($activeDeploymentCounts[$status] ?? 0),
                ])
                ->all(),
            'activeDeployments' => $activeDeployments
                ->with('repository.website.server')
                ->latest('builds.created_at')
                ->limit(5)
                ->get(),
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
            'attentionProviders' => $attentionProviders
                ->latest('connection_checked_at')
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
