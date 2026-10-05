<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\CountFilteredVisits;
use App\Contracts\Analytics\GoogleAnalyticsData;
use App\Contracts\Analytics\SearchConsole;
use App\Models\AnalyticsImport;
use App\Models\AnalyticsNotification;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsSiteViewer;
use App\Models\Project;
use App\Models\SavedView;
use App\Models\StorageBucket;
use App\Models\User;
use App\Queries\Analytics\FilteredVisitsQuery;
use App\Queries\Analytics\SearchConsolePropertiesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use DateTimeZone;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class ShowSiteController
{
    /**
     * Show a site's setup and settings: its tracking snippet and verification, Search Console, raw export, Google
     * Analytics imports, view-only access, reports and alerts, sharing, and the fields of its settings form.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  ProjectOverviewQuery  $overview
     * @param  SearchConsolePropertiesQuery  $searchConsoleProperties
     * @param  SearchConsole  $searchConsole
     * @param  GoogleAnalyticsData  $googleAnalytics
     * @param  FilteredVisitsQuery  $filtered
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, ProjectOverviewQuery $overview, SearchConsolePropertiesQuery $searchConsoleProperties, SearchConsole $searchConsole, GoogleAnalyticsData $googleAnalytics, FilteredVisitsQuery $filtered): JsonResponse
    {
        $canManage = $user->can('manageService', [$project, 'analytics']);
        $imports = $site->imports()->latest('id')->get();
        $connected = $imports->firstWhere('status', 'connected');
        $gaProperties = [];
        $gaError = null;
        if ($canManage && $connected !== null && $connected->refresh_token !== null) {
            try {
                $gaProperties = $googleAnalytics->properties($connected->refresh_token);
            } catch (RuntimeException $exception) {
                $gaError = $exception->getMessage();
            }
        }
        $left = $filtered->handle($site);
        $searchConsoleState = $canManage ? $searchConsoleProperties->handle($site) : ['properties' => [], 'error' => null];

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'publicId' => $site->public_id,
                'domains' => $site->domains,
                'timezone' => $site->timezone,
                'excludedPaths' => $site->excluded_paths ?? [],
                'excludedIps' => $site->excluded_ips ?? [],
                'customProperties' => $site->custom_properties ?? [],
                'contentGroups' => $site->content_groups ?? [],
                'blockedReferrers' => $site->blocked_referrers ?? [],
                'verified' => $site->isVerified(),
                'verifiedAt' => $site->verified_at?->toIso8601String(),
                'collecting' => $site->isCollectionAvailable(),
                'collectionEnabled' => $site->collection_enabled,
            ],
            'trackerUrl' => url('/tracker/v1.js'),
            'origin' => rtrim(url('/'), '/'),
            'filtered' => collect(CountFilteredVisits::REASONS)->filter(fn (string $label, string $reason): bool => ($left[$reason] ?? 0) > 0)
                ->map(fn (string $label, string $reason): array => ['label' => __($label), 'count' => (int) $left[$reason]])->values(),
            'searchConsole' => [
                'configured' => $searchConsole->configured(),
                'connected' => $site->search_console_token !== null,
                'property' => $site->search_console_property,
                'properties' => $searchConsoleState['properties'],
                'error' => $searchConsoleState['error'],
            ],
            'rawExport' => [
                'bucketId' => $site->export_bucket_id,
                'prefix' => $site->export_prefix,
                'exportedUntil' => $site->exported_until?->toDateString(),
                'error' => $site->export_error,
                'path' => ($site->export_prefix ? $site->export_prefix.'/' : '').'site='.$site->public_id.'/dt=YYYY-MM-DD/events.ndjson.gz',
                'buckets' => StorageBucket::query()->where('project_id', $project->id)->orderBy('name')->get()
                    ->map(fn (StorageBucket $bucket): array => ['value' => (string) $bucket->id, 'label' => "{$bucket->name} ({$bucket->bucket})"])->values(),
            ],
            'googleAnalytics' => [
                'configured' => $googleAnalytics->configured(),
                'connectedId' => $connected?->id,
                'properties' => $gaProperties,
                'error' => $gaError,
                'imports' => $imports->where('status', '!=', 'connected')->map(fn (AnalyticsImport $import): array => [
                    'id' => $import->id,
                    'property' => $import->property_name ?? $import->property,
                    'from' => $import->from_date?->toDateString(),
                    'until' => $import->until_date?->toDateString(),
                    'status' => $import->status,
                    'days' => $import->days_imported,
                    'error' => $import->error,
                ])->values(),
                'defaultFrom' => now($site->timezone)->subYear()->toDateString(),
                'defaultUntil' => now($site->timezone)->subDay()->toDateString(),
            ],
            'viewers' => $canManage ? $site->viewers()->orderBy('email')->get()->map(fn (AnalyticsSiteViewer $viewer): array => [
                'id' => $viewer->id,
                'email' => $viewer->email,
                'lastViewedAt' => $viewer->last_viewed_at?->toIso8601String(),
            ])->values() : [],
            'notifications' => $canManage ? $site->notifications()->orderBy('id')->get()->map(fn (AnalyticsNotification $notification): array => [
                'id' => $notification->id,
                'kind' => __(AnalyticsNotification::KINDS[$notification->kind] ?? $notification->kind),
                'destination' => $notification->destination(),
                'threshold' => $notification->threshold,
                'view' => $notification->view_name,
                'lastSentAt' => $notification->last_sent_at?->toIso8601String(),
                'error' => $notification->last_error,
            ])->values() : [],
            'notificationKinds' => collect(AnalyticsNotification::KINDS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => __($label)])->values(),
            'notificationChannels' => collect(AnalyticsNotification::CHANNELS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => __($label)])->values(),
            // The person's saved views of this site's report, for scheduled CSV exports.
            'savedViews' => SavedView::query()->where('user_id', $user->id)->where('page', 'analytics.overview')->orderBy('name')->get()
                ->filter(fn (SavedView $view): bool => (string) ($view->parameters['project'] ?? '') === (string) $project->id && (string) ($view->query['site'] ?? $site->id) === (string) $site->id)
                ->map(fn (SavedView $view): array => ['value' => (string) $view->id, 'label' => $view->name])->values(),
            'sharing' => $canManage ? [
                'url' => $site->share_token === null ? null : route('analytics.shared', $site->share_token),
                'embedUrl' => $site->share_token === null || $site->share_password !== null ? null : route('analytics.shared.embed', $site->share_token),
                'password' => $site->share_password !== null,
                'sharedAt' => $site->shared_at?->toIso8601String(),
            ] : null,
            'timezones' => DateTimeZone::listIdentifiers(),
            'canManage' => $canManage,
        ]);
    }
}
