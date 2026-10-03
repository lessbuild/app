<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Actions\Telemetry\PruneTelemetryData;
use App\Enums\IngestStatus;
use App\Enums\SelectionKind;
use App\Models\Account;
use App\Models\BillingSelection;
use App\Models\Environment;
use App\Models\IngestPayload;
use App\Models\IngestReceipt;
use App\Models\MetricSample;
use App\Models\MetricSeries;
use App\Models\Project;
use App\Models\TelemetryEvent;
use App\Models\TelemetryEventIdentity;
use App\Models\TelemetryUsageEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class RetentionPruningTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_plan_retention_prunes_expired_events_and_completed_receipts_but_preserves_recent_and_retryable_data(): void
    {
        $now = CarbonImmutable::parse('2026-09-21T12:00:00Z');
        $free = $this->accountOn('free');
        $freeEnvironment = Environment::factory()->for(Project::factory()->for($free)->withServices(['monitoring'])->create())->create();
        $oldEvent = TelemetryEvent::factory()->for($freeEnvironment)->create(['occurred_at' => $now->subDays(8)]);
        $recentEvent = TelemetryEvent::factory()->for($freeEnvironment)->create(['occurred_at' => $now->subDays(6)]);
        TelemetryEventIdentity::factory()->for($oldEvent, 'telemetryEvent')->create();
        TelemetryEventIdentity::factory()->for($recentEvent, 'telemetryEvent')->create();
        $series = MetricSeries::factory()->for($freeEnvironment)->create();
        MetricSample::factory()->for($series, 'metricSeries')->create([
            'telemetry_event_id' => $oldEvent->id,
            'occurred_at' => $oldEvent->occurred_at,
            'received_at' => $oldEvent->occurred_at,
        ]);

        $oldReceipt = IngestReceipt::factory()->for($freeEnvironment)->create([
            'account_id' => $free->id,
            'status' => IngestStatus::Completed,
            'received_at' => $now->subDays(8),
            'last_received_at' => $now->subDays(8),
            'processed_at' => $now->subDays(8),
        ]);
        IngestPayload::query()->create(['ingest_receipt_id' => $oldReceipt->id, 'payload' => []]);
        $failedReceipt = IngestReceipt::factory()->for($freeEnvironment)->failed()->create([
            'account_id' => $free->id,
            'received_at' => $now->subDays(8),
            'last_received_at' => $now->subDays(8),
        ]);
        IngestPayload::query()->create(['ingest_receipt_id' => $failedReceipt->id, 'payload' => []]);
        TelemetryUsageEntry::factory()->create(['account_id' => $free->id, 'received_at' => $now->subDays(8)]);

        $pro = $this->accountOn('pro');
        $proEnvironment = Environment::factory()->for(Project::factory()->for($pro)->withServices(['monitoring'])->create())->create();
        $proEvent = TelemetryEvent::factory()->for($proEnvironment)->create(['occurred_at' => $now->subDays(8)]);

        $summary = app(PruneTelemetryData::class)->handle(now: $now);

        $this->assertSame(['accounts' => 2, 'events' => 1, 'identities' => 1, 'receipts' => 1, 'payloads' => 1, 'dry_run' => false], $summary);
        $this->assertModelMissing($oldEvent);
        $this->assertModelExists($recentEvent);
        $this->assertModelExists($proEvent);
        $this->assertDatabaseCount('metric_samples', 0);
        $this->assertDatabaseCount('telemetry_event_identities', 1);
        $this->assertModelMissing($oldReceipt);
        $this->assertModelExists($failedReceipt);
        $this->assertDatabaseCount('ingest_payloads', 1);
        $this->assertDatabaseCount('telemetry_usage_entries', 1);
    }

    public function test_dry_run_reports_expired_records_without_deleting_them_and_workspace_filter_is_validated(): void
    {
        $now = CarbonImmutable::parse('2026-09-21T12:00:00Z');
        $account = $this->accountOn('free');
        $environment = Environment::factory()->for(Project::factory()->for($account)->withServices(['monitoring'])->create())->create();
        $event = TelemetryEvent::factory()->for($environment)->create(['occurred_at' => $now->subDays(8)]);

        $this->assertSame(0, Artisan::call('telemetry:prune', ['--dry-run' => true, '--account' => $account->id]));
        $this->assertStringContainsString('would prune 1 account(s), 1 event(s)', Artisan::output());
        $this->assertModelExists($event);

    }

    /** An account on a Monitoring tier (free unless a paid tier is chosen). */
    private function accountOn(string $tier): Account
    {
        $account = Account::factory()->create();
        if ($tier !== 'free') {
            $selection = new BillingSelection;
            $selection->forceFill(['account_id' => $account->id, 'service' => 'monitoring', 'kind' => SelectionKind::Tier, 'item_key' => $tier, 'quantity' => 1])->save();
        }

        return $account;
    }
}
