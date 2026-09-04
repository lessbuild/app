<?php

namespace App\Http\Controllers;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\RepositoryWebhookDelivery;
use App\Models\Server;
use App\Models\ServerCommandExecution;
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
        $activeCommands = $user->commandExecutions()
            ->whereIn('status', ServerCommandExecution::ACTIVE_STATUSES);
        $activeCommandCounts = (clone $activeCommands)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');
        $recentWebhookDeliveries = $user->webhookDeliveries()
            ->where('repository_webhook_deliveries.created_at', '>=', now()->subDay());
        $webhookDeliveryCounts = (clone $recentWebhookDeliveries)
            ->select('repository_webhook_deliveries.status', DB::raw('COUNT(*) as total'))
            ->groupBy('repository_webhook_deliveries.status')
            ->pluck('total', 'repository_webhook_deliveries.status');
        $provisioningServers = $user->servers()
            ->whereIn('provisioning_status', Server::ACTIVE_PROVISIONING_STATUSES);
        $provisioningWebsites = $user->websites()
            ->whereIn('provisioning_status', Website::ACTIVE_PROVISIONING_STATUSES);
        $provisioningCounts = [
            'servers' => (clone $provisioningServers)->count(),
            'websites' => (clone $provisioningWebsites)->count(),
        ];
        $provisioningResources = $provisioningServers
            ->select(['id', 'user_id', 'name', 'display_name', 'provisioning_status', 'created_at'])
            ->latest('id')
            ->limit(5)
            ->get()
            ->concat($provisioningWebsites
                ->select(['id', 'user_id', 'name', 'provisioning_status', 'created_at'])
                ->latest('id')
                ->limit(5)
                ->get())
            ->sortByDesc('created_at')
            ->take(5)
            ->values();
        $recipeUpdates = Recipe::query()
            ->published()
            ->whereNotNull('gallery_revision_at')
            ->whereExists(function ($installed) use ($user): void {
                $installed
                    ->selectRaw('1')
                    ->from('recipes as gallery_installs')
                    ->whereColumn('gallery_installs.source_recipe_id', 'recipes.id')
                    ->where('gallery_installs.user_id', $user->id)
                    ->where(function ($revision): void {
                        $revision
                            ->whereNull('gallery_installs.source_revision_at')
                            ->orWhereColumn('gallery_installs.source_revision_at', '<', 'recipes.gallery_revision_at');
                    });
            });
        $reportedGalleryRecipes = $user->recipes()
            ->published()
            ->whereHas('reports');

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
            'activeCommandCounts' => collect(ServerCommandExecution::ACTIVE_STATUSES)
                ->mapWithKeys(fn (string $status): array => [
                    $status => (int) ($activeCommandCounts[$status] ?? 0),
                ])
                ->all(),
            'activeCommands' => $activeCommands
                ->select(['id', 'server_id', 'user_id', 'status', 'created_at', 'started_at'])
                ->with('server:id,name,display_name')
                ->latest('id')
                ->limit(5)
                ->get(),
            'webhookDeliveryCounts' => collect(RepositoryWebhookDelivery::STATUSES)
                ->mapWithKeys(fn (string $status): array => [
                    $status => (int) ($webhookDeliveryCounts[$status] ?? 0),
                ])
                ->all(),
            'recentWebhookDeliveries' => $recentWebhookDeliveries
                ->select([
                    'repository_webhook_deliveries.id',
                    'repository_webhook_deliveries.repository_id',
                    'repository_webhook_deliveries.status',
                    'repository_webhook_deliveries.created_at',
                ])
                ->with('repository:id,name')
                ->latest('repository_webhook_deliveries.id')
                ->limit(5)
                ->get(),
            'provisioningCounts' => $provisioningCounts,
            'provisioningResources' => $provisioningResources,
            'recipeUpdateCount' => (clone $recipeUpdates)->count(),
            'recipeUpdates' => $recipeUpdates
                ->select(['id', 'user_id', 'name', 'category', 'gallery_revision_at'])
                ->with([
                    'user:id,name',
                    'installs' => fn ($query) => $query
                        ->where('user_id', $user->id)
                        ->select(['id', 'user_id', 'source_recipe_id', 'name', 'source_revision_at'])
                        ->oldest('source_revision_at'),
                ])
                ->latest('gallery_revision_at')
                ->limit(5)
                ->get(),
            'communityReportCount' => DB::table('recipe_reports')
                ->whereIn('recipe_id', (clone $reportedGalleryRecipes)->select('recipes.id'))
                ->count(),
            'reportedGalleryRecipeCount' => (clone $reportedGalleryRecipes)->count(),
            'reportedGalleryRecipes' => $reportedGalleryRecipes
                ->select(['recipes.id', 'recipes.user_id', 'recipes.name', 'recipes.category'])
                ->withCount('reports')
                ->orderByDesc('reports_count')
                ->latest('recipes.id')
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
