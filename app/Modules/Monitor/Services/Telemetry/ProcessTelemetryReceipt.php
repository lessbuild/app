<?php

namespace App\Modules\Monitor\Services\Telemetry;

use App\Modules\Monitor\Data\Telemetry\IngestStatus;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\IngestReceipt;
use App\Modules\Monitor\Models\TelemetryEventIdentity;
use App\Modules\Monitor\Models\TelemetryUsageEntry;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\RecordReleases;
use App\Modules\Monitor\Services\WorkspaceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

final class ProcessTelemetryReceipt
{
    public function __construct(
        private readonly TelemetryQueue $queue,
        private readonly TelemetryEventWriter $writer,
        private readonly RecordReleases $releases,
        private readonly RecordMetricSamples $metrics,
        private readonly WorkspaceUsage $usage,
    ) {}

    /** Returns a release delay when an early delivery must wait for its retry window. */
    public function process(string $receiptId, int $generation): ?int
    {
        $claim = DB::connection('monitor')->transaction(function () use ($receiptId, $generation): IngestReceipt|int|null {
            $receipt = $this->queue->lockReceipt($receiptId);

            if ($receipt === null || $receipt->generation !== $generation
                || in_array($receipt->status, [IngestStatus::Completed, IngestStatus::Failed], true)) {
                return null;
            }

            if ($receipt->next_attempt_at?->isFuture()) {
                return $receipt->status === IngestStatus::Processing
                    ? null
                    : max(1, (int) ceil(now('UTC')->diffInSeconds($receipt->next_attempt_at)));
            }

            if ($receipt->processing_attempts >= TelemetryQueue::MAX_ATTEMPTS) {
                $this->queue->markFailed($receipt, 'worker_interrupted');

                return null;
            }

            $receipt->forceFill([
                'status' => IngestStatus::Processing,
                'processing_attempts' => $receipt->processing_attempts + 1,
                'processing_started_at' => now('UTC'),
                'processing_token' => (string) Str::uuid(),
                'next_attempt_at' => now('UTC')->addSeconds(TelemetryQueue::RETRY_AFTER),
            ])->save();

            return $receipt;
        }, attempts: 3);

        if (! $claim instanceof IngestReceipt) {
            return $claim;
        }

        try {
            DB::connection('monitor')->transaction(function () use ($claim): void {
                $receipt = $this->queue->lockReceipt($claim->id);

                if ($receipt === null || $receipt->status !== IngestStatus::Processing
                    || $receipt->generation !== $claim->generation || $receipt->processing_token !== $claim->processing_token) {
                    return;
                }

                $environment = Environment::withTrashed()->find($receipt->environment_id);

                if ($environment === null || $environment->application()->withTrashed()->value('workspace_id') !== $receipt->workspace_id) {
                    $this->queue->markFailed($receipt, 'source_removed');
                    $receipt->ingestPayload()->delete();

                    return;
                }

                $payload = $receipt->ingestPayload()->first()?->payload;

                if ($receipt->accepted_count < 1 || ! is_array($payload) || ! array_is_list($payload) || count($payload) !== $receipt->accepted_count) {
                    throw new UnexpectedValueException('The stored ingestion payload is unavailable.');
                }

                $workspace = Workspace::query()->lockForUpdate()->find($receipt->workspace_id);
                if ($workspace === null) {
                    $this->queue->markFailed($receipt, 'source_removed');
                    $receipt->ingestPayload()->delete();

                    return;
                }
                if (! $this->usage->canAccept($workspace, $receipt->accepted_count, $receipt->received_at)) {
                    $this->queue->markFailed($receipt, 'plan_limit_reached');

                    return;
                }

                $identities = TelemetryEventIdentity::query()->where('ingest_receipt_id', $receipt->id)
                    ->where('environment_id', $environment->id)->whereIn('id', array_column($payload, 'identity_id'))
                    ->get()->keyBy('id');

                $releaseIds = $this->releases->record($environment, $payload, $receipt->source, $receipt->received_at);
                $metricPoints = [];

                foreach ($payload as $position => $item) {
                    $identity = $identities->get($item['identity_id'] ?? null);

                    if ($identity === null || $identity->telemetry_event_id !== null || ! is_array($item['event'] ?? null)) {
                        throw new UnexpectedValueException('The stored ingestion identity is unavailable.');
                    }

                    $record = $this->writer->store($environment, $item['event'], $identity->dedupe_key, $receipt->received_at, $releaseIds[$position] ?? null);
                    $identity->forceFill(['telemetry_event_id' => $record->id])->save();
                    if (isset($item['metric_projection'])) {
                        $metricPoints[] = ['projection' => $item['metric_projection'], 'event_id' => $record->id];
                    }
                }
                $this->metrics->record($environment, $metricPoints, $receipt->received_at);

                TelemetryUsageEntry::query()->create([
                    'workspace_id' => $receipt->workspace_id,
                    'environment_id' => $environment->id,
                    'ingest_receipt_id' => $receipt->id,
                    'source' => $receipt->source,
                    'event_count' => $receipt->accepted_count,
                    'received_at' => $receipt->received_at,
                ]);
                $environment->newQuery()->withTrashed()->whereKey($environment->id)->update([
                    'event_count' => DB::connection('monitor')->raw('event_count + '.$receipt->accepted_count),
                    'last_seen_at' => $environment->last_seen_at === null
                        ? $receipt->received_at
                        : $receipt->received_at->max($environment->last_seen_at),
                ]);
                $receipt->forceFill([
                    'status' => IngestStatus::Completed,
                    'processed_at' => CarbonImmutable::now('UTC'),
                    'processing_token' => null,
                    'next_attempt_at' => null,
                    'failed_at' => null,
                    'last_error_code' => null,
                ])->save();
                $receipt->ingestPayload()->delete();
            }, attempts: 3);
        } catch (Throwable $exception) {
            $permanent = $exception instanceof DecryptException || $exception instanceof UnexpectedValueException;
            $code = $permanent ? 'payload_unavailable' : ($exception instanceof QueryException ? 'storage_unavailable' : 'processing_failed');
            DB::connection('monitor')->transaction(function () use ($claim, $code, $permanent): void {
                $receipt = $this->queue->lockReceipt($claim->id);

                if ($receipt === null || $receipt->generation !== $claim->generation || $receipt->processing_token !== $claim->processing_token) {
                    return;
                }

                if ($permanent || $receipt->processing_attempts >= TelemetryQueue::MAX_ATTEMPTS) {
                    $this->queue->markFailed($receipt, $code);

                    return;
                }

                $receipt->forceFill([
                    'status' => IngestStatus::Retrying,
                    'last_error_code' => $code,
                    'processing_token' => null,
                    'next_attempt_at' => now('UTC')->addSeconds(TelemetryQueue::BACKOFF[min($receipt->processing_attempts - 1, 3)]),
                ])->save();
            }, attempts: 3);

            if (! $permanent) {
                throw $exception;
            }
        }

        return null;
    }

    public function failed(string $receiptId, int $generation): void
    {
        DB::connection('monitor')->transaction(function () use ($receiptId, $generation): void {
            $receipt = $this->queue->lockReceipt($receiptId);

            if ($receipt !== null && $receipt->generation === $generation && $receipt->status !== IngestStatus::Completed) {
                $this->queue->markFailed($receipt, $receipt->last_error_code ?? 'worker_interrupted');
            }
        }, attempts: 3);
    }
}
