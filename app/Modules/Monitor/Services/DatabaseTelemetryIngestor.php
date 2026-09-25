<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Contracts\TelemetryIngestor;
use App\Modules\Monitor\Data\Telemetry\IngestContext;
use App\Modules\Monitor\Data\Telemetry\IngestResult;
use App\Modules\Monitor\Data\Telemetry\IngestStatus;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\IngestReceipt;
use App\Modules\Monitor\Models\TelemetryEventIdentity;
use App\Modules\Monitor\Services\Core\MonitorDeletionFence;
use App\Modules\Monitor\Services\Telemetry\IngestIdentity;
use App\Modules\Monitor\Services\Telemetry\LegacyEventReplay;
use App\Modules\Monitor\Services\Telemetry\MetricProjection;
use App\Modules\Monitor\Services\Telemetry\TelemetryEventWriter;
use App\Modules\Monitor\Services\Telemetry\TelemetryPayloadGuard;
use App\Modules\Monitor\Services\Telemetry\TelemetryQueue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseTelemetryIngestor implements TelemetryIngestor
{
    public function __construct(
        private readonly TelemetryEventWriter $writer,
        private readonly TelemetryPayloadGuard $guard,
        private readonly IngestIdentity $identity,
        private readonly LegacyEventReplay $legacyReplay,
        private readonly TelemetryQueue $queue,
        private readonly MetricProjection $metrics,
    ) {}

    /** @param list<array<string, mixed>> $events */
    public function ingest(Environment $environment, string $batchId, array $events, ?IngestContext $context = null): IngestResult
    {
        $context ??= new IngestContext;
        $receivedAt = CarbonImmutable::now('UTC');
        $this->guard->assertNormalizedSize($events);
        $preparedEvents = array_map($this->writer->prepare(...), $events);
        $this->guard->assertNormalizedSize($preparedEvents);

        if ($events === []) {
            return new IngestResult($batchId, 0, 0);
        }

        $identityEvents = $this->identity->fingerprintEvents($events, $context);
        $fingerprints = $this->identity->fingerprints($identityEvents);
        $receiptKeys = $this->identity->receiptKeys($environment->id, $batchId, $context, $fingerprints);
        $eventFingerprints = array_map($this->identity->fingerprints(...), $identityEvents);
        $eventKeys = [];

        foreach ($events as $index => $event) {
            $eventKeys[$index] = $this->identity->eventKey($environment->id, $batchId, $context, $event, $index);
        }

        return DB::connection('monitor')->transaction(function () use ($environment, $batchId, $events, $preparedEvents, $context, $receivedAt, $fingerprints, $receiptKeys, $eventFingerprints, $eventKeys): IngestResult {
            $application = Application::query()->lockForUpdate()->find($environment->application_id);
            $environment = Environment::query()->lockForUpdate()->find($environment->id);
            abort_if($application === null || $environment === null || $environment->status !== 'active', 401, 'The ingestion token is invalid.');
            abort_if(MonitorDeletionFence::lockWorkspace($application->workspace_id), 410, 'This Monitor workspace is being deleted.');

            if ($context->tokenId !== null) {
                $token = $environment->ingestTokens()->active()->lockForUpdate()->find($context->tokenId);
                abort_if($token === null, 401, 'The ingestion token is invalid.');
            }

            $receipt = IngestReceipt::query()->whereIn('receipt_key', $receiptKeys)->first();

            if ($receipt !== null) {
                $this->identity->assertMatches($receipt->payload_fingerprint, $fingerprints, 'batch_id');
                $receipt->forceFill([
                    'attempt_count' => $receipt->attempt_count + 1,
                    'last_received_at' => $receivedAt->max($receipt->last_received_at),
                ])->save();

                return new IngestResult($batchId, 0, count($events), $receipt->id, true, $receipt->status);
            }

            $receipt = IngestReceipt::query()->create([
                'workspace_id' => $application->workspace_id,
                'environment_id' => $environment->id,
                'receipt_key' => $receiptKeys[0],
                'payload_fingerprint' => $fingerprints[0],
                'source' => $context->source,
                'status' => IngestStatus::Queued,
                'event_count' => count($events),
                'received_at' => $receivedAt,
                'last_received_at' => $receivedAt,
            ]);
            $pendingEvents = [];
            $duplicates = 0;
            $identities = TelemetryEventIdentity::query()->whereIn('dedupe_key', $eventKeys)->get()->keyBy('dedupe_key');
            $legacyCandidates = $this->legacyReplay->candidates($environment, $batchId, $events);

            foreach ($preparedEvents as $index => $event) {
                $dedupeKey = $eventKeys[$index];
                $eventIdentity = $identities->get($dedupeKey);

                if ($eventIdentity !== null) {
                    $this->identity->assertMatches($eventIdentity->payload_fingerprint, $eventFingerprints[$index], 'events.'.$index.'.id');
                    $duplicates++;

                    continue;
                }

                $attributes = $this->writer->attributes($environment, $event, $receivedAt);
                $legacyRecord = $this->legacyReplay->find($environment, $batchId, $context, $events[$index], $index, $attributes, $legacyCandidates);
                $eventIdentity = TelemetryEventIdentity::query()->create([
                    'environment_id' => $environment->id,
                    'ingest_receipt_id' => $receipt->id,
                    'telemetry_event_id' => $legacyRecord?->id,
                    'dedupe_key' => $dedupeKey,
                    'version' => 2,
                    'payload_fingerprint' => $eventFingerprints[$index][0],
                ]);
                $identities->put($dedupeKey, $eventIdentity);

                if ($legacyRecord !== null) {
                    $duplicates++;

                    continue;
                }

                $pendingEvents[] = [
                    'identity_id' => $eventIdentity->id, 'event' => $event,
                    'metric_projection' => $this->metrics->prepare($events[$index], $context->source, $receivedAt),
                ];
            }

            $accepted = count($pendingEvents);
            $receipt->forceFill([
                'status' => $accepted > 0 ? IngestStatus::Queued : IngestStatus::Completed,
                'accepted_count' => $accepted,
                'duplicate_count' => $duplicates,
                'next_attempt_at' => $accepted > 0 ? $receivedAt : null,
                'processed_at' => $accepted > 0 ? null : CarbonImmutable::now('UTC'),
            ])->save();

            if ($accepted > 0) {
                $receipt->ingestPayload()->create(['payload' => $pendingEvents]);
                $this->queue->dispatch($receipt);
            }

            return new IngestResult($batchId, $accepted, $duplicates, $receipt->id, status: $receipt->status);
        }, attempts: 3);
    }
}
