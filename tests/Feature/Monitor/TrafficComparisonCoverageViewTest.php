<?php

namespace Tests\Feature\Monitor;

use App\Core\Data\Projects\ProjectTrafficWindowSummary;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class TrafficComparisonCoverageViewTest extends TestCase
{
    public function test_it_distinguishes_no_analytics_intake_from_confirmed_zero_activity(): void
    {
        $summary = new ProjectTrafficWindowSummary(pageviews: 0, visitors: 0);
        $html = Blade::render(
            '<x-monitor::ui.traffic-comparison :before="$summary" :after="$summary" />',
            ['summary' => $summary],
        );

        $this->assertStringContainsString('Batches accepted', $html);
        $this->assertStringContainsString('No Analytics batches were accepted during this window.', $html);
        $this->assertStringContainsString('not confirmation of zero site activity.', $html);
        $this->assertStringContainsString('cannot infer missed client-side events or tracker sampling', $html);
    }

    public function test_it_does_not_call_batchless_event_counts_zero_when_no_batches_were_accepted(): void
    {
        $summary = new ProjectTrafficWindowSummary(pageviews: 2, visitors: 1);
        $html = Blade::render(
            '<x-monitor::ui.traffic-comparison :before="$summary" :after="$summary" />',
            ['summary' => $summary],
        );

        $this->assertStringContainsString('Event-time counts may include historical events without batch records', $html);
        $this->assertStringNotContainsString('Zero observed event counts are not confirmation of zero site activity.', $html);
    }

    public function test_it_reports_partial_and_complete_batch_processing_without_claiming_tracker_coverage(): void
    {
        $before = new ProjectTrafficWindowSummary(
            pageviews: 12,
            visitors: 8,
            acceptedBatches: 4,
            processedBatches: 2,
            unprocessedBatches: 1,
            failedBatches: 1,
        );
        $after = new ProjectTrafficWindowSummary(
            pageviews: 0,
            visitors: 0,
            acceptedBatches: 3,
            processedBatches: 3,
        );
        $html = Blade::render(
            '<x-monitor::ui.traffic-comparison :before="$before" :after="$after" />',
            ['before' => $before, 'after' => $after],
        );

        $this->assertStringContainsString('2 of 4 batches accepted during this intake-time window have a processed status.', $html);
        $this->assertStringContainsString('Pending, failed, or unresolved batches may make event totals incomplete.', $html);
        $this->assertStringContainsString('All 3 batches accepted during this intake-time window have a processed status.', $html);
        $this->assertStringContainsString('This does not confirm tracker delivery or complete event-time coverage.', $html);
    }
}
