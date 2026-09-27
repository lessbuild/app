<?php

declare(strict_types=1);

namespace App\Services\Telemetry;

use App\Contracts\Telemetry\TelemetryIngestor;
use App\Data\Telemetry\IngestContext;
use App\Data\Telemetry\IngestResult;
use App\Enums\IngestStatus;
use App\Models\Environment;
use App\Models\IngestReceipt;
use App\Models\Project;
use App\Models\TelemetryEventIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseTelemetryIngestor implements TelemetryIngestor
{
    public function __construct(
        private readonly TelemetryEventWriter $writer,
        private readonly TelemetryPayloadGuard $guard,
        private readonly IngestIdentity $identity,
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

        return DB::transaction(function () use ($environment, $batchId, $events, $preparedEvents, $context, $receivedAt, $fingerprints, $receiptKeys, $eventFingerprints, $eventKeys): IngestResult {
            $project = Project::query()->lockForUpdate()->find($environment->project_id);
            $environment = Environment::query()->lockForUpdate()->find($environment->id);
            abort_if($project === null || $environment === null || ! $project->hasService('monitoring'), 401, 'The ingestion token is invalid.');

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
                'account_id' => $project->account_id,
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

            foreach ($preparedEvents as $index => $event) {
                $dedupeKey = $eventKeys[$index];
                $eventIdentity = $identities->get($dedupeKey);

                if ($eventIdentity !== null) {
                    $this->identity->assertMatches($eventIdentity->payload_fingerprint, $eventFingerprints[$index], 'events.'.$index.'.id');
                    $duplicates++;

                    continue;
                }

                $eventIdentity = TelemetryEventIdentity::query()->create([
                    'environment_id' => $environment->id,
                    'ingest_receipt_id' => $receipt->id,
                    'telemetry_event_id' => null,
                    'dedupe_key' => $dedupeKey,
                    'version' => 2,
                    'payload_fingerprint' => $eventFingerprints[$index][0],
                ]);
                $identities->put($dedupeKey, $eventIdentity);

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
