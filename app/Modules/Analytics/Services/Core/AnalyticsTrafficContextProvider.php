<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\ProjectTrafficContextProvider;
use App\Core\Data\Projects\ProjectTrafficWindowSummary;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use App\Modules\Analytics\Enums\IngestionStatus;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\GoalConversion;
use App\Modules\Analytics\Models\IngestionBatch;
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
            ->reportEligible();

        $pageviews = (clone $events)->count();
        $visitors = (clone $events)
            ->whereNotNull('visitor_hash')
            ->distinct()
            ->count('visitor_hash');
        $conversions = GoalConversion::query()
            ->where('site_id', $site->getKey())
            ->where('converted_at', '>=', $from->utc())
            ->where('converted_at', '<', $until->utc())
            ->whereHas('event', fn ($event) => $event->reportEligible());
        $conversionCount = (clone $conversions)->count();
        $convertedVisits = (clone $conversions)->whereNotNull('visit_id')->distinct()->count('visit_id');
        $batches = IngestionBatch::query()
            ->where('site_id', $site->getKey())
            ->where('accepted_at', '>=', $from->utc())
            ->where('accepted_at', '<', $until->utc());
        $acceptedBatches = (clone $batches)->count();
        $processedBatches = (clone $batches)->where('status', IngestionStatus::Processed->value)->count();
        $unprocessedBatches = (clone $batches)
            ->whereIn('status', [IngestionStatus::Pending->value, IngestionStatus::Processing->value])
            ->count();
        $failedBatches = (clone $batches)->where('status', IngestionStatus::Failed->value)->count();

        return new ProjectTrafficWindowSummary(
            pageviews: $pageviews,
            visitors: $visitors,
            conversions: $conversionCount,
            convertedVisits: $convertedVisits,
            latestBatchProcessedAt: $site->last_processed_at === null
                ? null
                : CarbonImmutable::instance($site->last_processed_at)->utc(),
            sourceUrl: Route::has('analytics.dashboard')
                ? route('analytics.dashboard', ['site' => $site->getKey()])
                : null,
            unprocessedBatches: $unprocessedBatches,
            failedBatches: $failedBatches,
            acceptedBatches: $acceptedBatches,
            processedBatches: $processedBatches,
        );
    }
}
