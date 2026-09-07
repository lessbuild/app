<?php

namespace App\Http\Controllers;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\RepositoryWebhookDelivery;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\Website;
use App\Models\WebsiteHealthCheck;
use App\Services\PlanLimits;
use App\Services\PublicPlatformStatus;
use App\Services\SystemHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public const WIDGETS = ['stats', 'setup', 'status', 'providers'];

    /**
     * Render current-workspace operational metrics, attention items, recent activity, plan usage, and selected dashboard widgets.
     *
     * System-health details are included only for workspace managers.
     */
    public function __invoke(
        Request $request,
        SystemHealth $systemHealth,
        PublicPlatformStatus $platformStatus,
        PlanLimits $limits,
    ): View {
        $user = $request->user();
        $organization = $user->currentOrganization;
        $workspaceBuilds = Build::query()
            ->whereHas('repository', fn ($query) => $query->where('organization_id', $organization->id));
        $workspaceCommands = ServerCommandExecution::query()
            ->whereHas('server', fn ($query) => $query->where('organization_id', $organization->id));
        $workspaceWebhookDeliveries = RepositoryWebhookDelivery::query()
            ->whereHas('repository', fn ($query) => $query->where('organization_id', $organization->id));
        $canManageSystemHealth = $user->currentOrganization?->permits($user, 'manage') ?? false;
        $attentionWebsites = $user->workspaceWebsites()->where(function ($query): void {
            $query
                ->where('provisioning_status', Website::STATUS_FAILED)
                ->orWhere(function ($query): void {
                    $query
                        ->where('health_check_enabled', true)
                        ->where('health_status', Website::HEALTH_UNHEALTHY);
                });
        });
        $attentionServers = $user->workspaceServers()
            ->where('provisioning_status', Server::STATUS_FAILED);
        $attentionRepositories = $user->workspaceRepositories()
            ->whereHas('latestBuild', fn ($query) => $query->where('status', Build::STATUS_FAILED));
        $providers = $user->workspaceProviders();
        $attentionProviders = (clone $providers)
            ->where('connection_status', Provider::CONNECTION_FAILED);
        $activeDeployments = (clone $workspaceBuilds)
            ->whereIn('builds.status', Build::ACTIVE_STATUSES);
        $activeDeploymentCounts = (clone $activeDeployments)
            ->select('builds.status', DB::raw('COUNT(*) as total'))
            ->groupBy('builds.status')
            ->pluck('total', 'builds.status');
        $activeCommands = (clone $workspaceCommands)
            ->whereIn('status', ServerCommandExecution::ACTIVE_STATUSES);
        $activeCommandCounts = (clone $activeCommands)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');
        $recentWebhookDeliveries = (clone $workspaceWebhookDeliveries)
            ->where('repository_webhook_deliveries.created_at', '>=', now()->subDay());
        $webhookDeliveryCounts = (clone $recentWebhookDeliveries)
            ->select('repository_webhook_deliveries.status', DB::raw('COUNT(*) as total'))
            ->groupBy('repository_webhook_deliveries.status')
            ->pluck('total', 'repository_webhook_deliveries.status');
        $provisioningServers = $user->workspaceServers()
            ->whereIn('provisioning_status', Server::ACTIVE_PROVISIONING_STATUSES);
        $provisioningWebsites = $user->workspaceWebsites()
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
        $reportedGalleryRecipes = $user->workspaceRecipes()
            ->published()
            ->whereHas('reports', fn ($query) => $query->whereNull('resolved_at'));
        $communityReports = DB::table('recipe_reports')
            ->whereIn('recipe_id', (clone $reportedGalleryRecipes)->select('recipes.id'))
            ->whereNull('resolved_at');
        $onboarding = [
            'provider' => (clone $providers)->exists(),
            'server' => $user->workspaceServers()->exists(),
            'website' => $user->workspaceWebsites()->exists(),
            'repository' => $user->workspaceRepositories()->exists(),
            'deployment' => (clone $workspaceBuilds)->where('builds.status', Build::STATUS_SUCCEEDED)->exists(),
        ];
        $trendStart = now()->startOfDay()->subDays(13);
        $trendBuilds = (clone $workspaceBuilds)
            ->where('builds.created_at', '>=', $trendStart)
            ->get(['builds.id', 'builds.status', 'builds.created_at', 'builds.started_at', 'builds.finished_at']);
        $trendHealthChecks = WebsiteHealthCheck::query()
            ->whereHas('website', fn ($query) => $query->where('organization_id', $organization->id))
            ->where('checked_at', '>=', $trendStart)
            ->get(['successful', 'duration_ms', 'checked_at']);
        $days = collect(range(0, 13))->map(fn (int $offset) => $trendStart->copy()->addDays($offset));
        $deploymentTrend = $days->map(function ($day) use ($trendBuilds): array {
            $builds = $trendBuilds->filter(fn (Build $build) => $build->created_at->isSameDay($day));

            return [
                'date' => $day->toDateString(),
                'label' => $day->format('D'),
                'total' => $builds->count(),
                'succeeded' => $builds->where('status', Build::STATUS_SUCCEEDED)->count(),
                'failed' => $builds->where('status', Build::STATUS_FAILED)->count(),
            ];
        });
        $healthTrend = $days->map(function ($day) use ($trendHealthChecks): array {
            $checks = $trendHealthChecks->filter(fn (WebsiteHealthCheck $check) => $check->checked_at->isSameDay($day));
            $successful = $checks->where('successful', true)->count();

            return [
                'date' => $day->toDateString(),
                'label' => $day->format('D'),
                'total' => $checks->count(),
                'rate' => $checks->isEmpty() ? null : (int) round(($successful / $checks->count()) * 100),
            ];
        });
        $terminalTrendBuilds = $trendBuilds->whereIn('status', Build::TERMINAL_STATUSES);
        $durations = $terminalTrendBuilds->map->durationSeconds()->filter(fn ($seconds) => $seconds !== null)->sort()->values();

        return view('dashboard', [
            'canManageSystemHealth' => $canManageSystemHealth,
            'systemHealth' => $canManageSystemHealth ? $systemHealth->summary() : null,
            'platformStatus' => $canManageSystemHealth ? null : $platformStatus->snapshot(),
            'onboarding' => $onboarding,
            'dashboardWidgets' => $user->preferences['dashboard_widgets'] ?? self::WIDGETS,
            'stats' => [
                'websites' => $user->workspaceWebsites()->count(),
                'servers' => $user->workspaceServers()->count(),
                'builds' => (clone $workspaceBuilds)->count(),
                'repositories' => $user->workspaceRepositories()->count(),
            ],
            'deploymentTrend' => $deploymentTrend,
            'deploymentTrendMaximum' => max(1, (int) $deploymentTrend->max('total')),
            'healthTrend' => $healthTrend,
            'trendSummary' => [
                'deployments' => $trendBuilds->count(),
                'success_rate' => $terminalTrendBuilds->isEmpty() ? null : (int) round(($terminalTrendBuilds->where('status', Build::STATUS_SUCCEEDED)->count() / $terminalTrendBuilds->count()) * 100),
                'median_duration' => $durations->isEmpty() ? null : Build::formatDuration((int) $durations->median()),
                'health_checks' => $trendHealthChecks->count(),
                'health_rate' => $trendHealthChecks->isEmpty() ? null : (int) round(($trendHealthChecks->where('successful', true)->count() / $trendHealthChecks->count()) * 100),
            ],
            'billingPlan' => [
                'key' => $organization->owner->billingPlan(),
                'name' => config('billing.plans.'.$organization->owner->billingPlan().'.name'),
                'usage' => collect(['servers', 'websites', 'members'])
                    ->mapWithKeys(fn (string $resource): array => [$resource => $limits->usageForOrganization($organization, $resource)])
                    ->all(),
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
            'communityReportCount' => (clone $communityReports)->count(),
            'communityReportAttention' => [
                'security' => (clone $communityReports)->where('reason', 'security')->count(),
                'stale' => (clone $communityReports)->where('created_at', '<=', now()->subDays(7))->count(),
            ],
            'reportedGalleryRecipeCount' => (clone $reportedGalleryRecipes)->count(),
            'reportedGalleryRecipes' => $reportedGalleryRecipes
                ->select(['recipes.id', 'recipes.user_id', 'recipes.name', 'recipes.category'])
                ->withCount(['reports' => fn ($query) => $query->whereNull('resolved_at')])
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
            'recentWebsites' => $user->workspaceWebsites()
                ->with('server')
                ->latest()
                ->limit(5)
                ->get(),
            'recentBuilds' => (clone $workspaceBuilds)
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

    /**
     * Validate distinct supported widget names, save the user's dashboard selection, and redirect with an acknowledgement.
     */
    public function updatePreferences(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'widgets' => ['nullable', 'array'],
            'widgets.*' => ['required', Rule::in(self::WIDGETS), 'distinct'],
        ]);
        $preferences = $request->user()->preferences ?? [];
        $preferences['dashboard_widgets'] = array_values($data['widgets'] ?? []);
        $request->user()->update(['preferences' => $preferences]);

        return back()->with('success', __('Dashboard layout saved.'));
    }
}
