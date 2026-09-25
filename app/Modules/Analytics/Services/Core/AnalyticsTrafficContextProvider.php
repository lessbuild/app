<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\ProjectTrafficContextProvider;
use App\Core\Data\Projects\ProjectTrafficWindowSummary;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\GoalConversion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

final class AnalyticsTrafficContextProvider implements ProjectTrafficContextProvider
{
    public function __construct(private readonly AnalyticsProjectLink $sites) {}

    public function aggregate(
        PlatformUser $user,
        Project $project,
        ProjectResource $resource,
        CarbonImmutable $from,
        CarbonImmutable $until,
    ): ?ProjectTrafficWindowSummary {
        if ($resource->product !== 'analytics'
            || $resource->resource_type !== 'site'
            || $resource->status !== 'active') {
            return null;
        }

        $site = $this->sites->accessibleSites($user, $project)
            ->first(fn ($candidate): bool => (string) $candidate->getKey() === (string) $resource->resource_id);

        if ($site === null) {
            return null;
        }

        $events = AnalyticsEvent::query()
            ->where('site_id', $site->getKey())
            ->where('type', 'pageview')
            ->where('occurred_at', '>=', $from->utc())
            ->where('occurred_at', '<', $until->utc())
            ->whereNotNull('ingestion_batch_id')
            ->whereHas('ingestionBatch', fn ($batch) => $batch
                ->where('status', 'processed')
                ->whereNotNull('processed_at'));

        $pageviews = (clone $events)->count();
        $visitors = (clone $events)
            ->whereNotNull('visitor_hash')
            ->distinct()
            ->count('visitor_hash');
        $conversions = GoalConversion::query()
            ->where('site_id', $site->getKey())
            ->where('converted_at', '>=', $from->utc())
            ->where('converted_at', '<', $until->utc())
            ->whereHas('event', fn ($event) => $event
                ->whereNotNull('ingestion_batch_id')
                ->whereHas('ingestionBatch', fn ($batch) => $batch
                    ->where('status', 'processed')
                    ->whereNotNull('processed_at')));
        $conversionCount = (clone $conversions)->count();
        $convertedVisits = (clone $conversions)->whereNotNull('visit_id')->distinct()->count('visit_id');

        return new ProjectTrafficWindowSummary(
            pageviews: $pageviews,
            visitors: $visitors,
            conversions: $conversionCount,
            convertedVisits: $convertedVisits,
            processedAt: $site->last_processed_at === null
                ? null
                : CarbonImmutable::instance($site->last_processed_at)->utc(),
            sourceUrl: Route::has('analytics.dashboard')
                ? route('analytics.dashboard', ['site' => $site->getKey()])
                : null,
        );
    }
}
