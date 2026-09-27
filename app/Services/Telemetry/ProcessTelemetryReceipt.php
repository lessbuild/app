<?php

declare(strict_types=1);

namespace App\Services\Telemetry;

use App\Actions\Billing\RecordUsage;
use App\Enums\IngestStatus;
use App\Models\Account;
use App\Models\Environment;
use App\Models\IngestReceipt;
use App\Models\TelemetryEventIdentity;
use App\Models\TelemetryUsageEntry;
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
        private readonly TelemetryUsage $usage,
        private readonly RecordUsage $recordUsage,
    ) {}

    /** Returns a release delay when an early delivery must wait for its retry window. */
    public function process(string $receiptId, int $generation): ?int
    {
        $claim = DB::transaction(function () use ($receiptId, $generation): IngestReceipt|int|null {
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
            DB::transaction(function () use ($claim): void {
                $receipt = $this->queue->lockReceipt($claim->id);

                if ($receipt === null || $receipt->status !== IngestStatus::Processing
                    || $receipt->generation !== $claim->generation || $receipt->processing_token !== $claim->processing_token) {
                    return;
                }

                $environment = $receipt->environment_id === null ? null : Environment::query()->find($receipt->environment_id);

                if ($environment === null || $environment->project()->value('account_id') !== $receipt->account_id) {
                    $this->queue->markFailed($receipt, 'source_removed');
                    $receipt->ingestPayload()->delete();

                    return;
                }

                $payload = $receipt->ingestPayload()->first()?->payload;

                if ($receipt->accepted_count < 1 || ! is_array($payload) || ! array_is_list($payload) || count($payload) !== $receipt->accepted_count) {
                    throw new UnexpectedValueException('The stored ingestion payload is unavailable.');
                }

                $account = Account::query()->lockForUpdate()->find($receipt->account_id);
                if ($account === null) {
                    $this->queue->markFailed($receipt, 'source_removed');
                    $receipt->ingestPayload()->delete();

                    return;
                }
                if (! $this->usage->canAccept($account, $receipt->accepted_count, $receipt->received_at)) {
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
                    'account_id' => $receipt->account_id,
                    'environment_id' => $environment->id,
                    'ingest_receipt_id' => $receipt->id,
                    'source' => $receipt->source,
                    'event_count' => $receipt->accepted_count,
                    'received_at' => $receipt->received_at,
                ]);
                $this->recordUsage->handle($receipt->account_id, TelemetryUsage::METER, $receipt->accepted_count, $receipt->received_at);
                Environment::query()->whereKey($environment->id)->increment('telemetry_event_count', $receipt->accepted_count, [
                    'telemetry_last_received_at' => $environment->telemetry_last_received_at === null
                        ? $receipt->received_at
                        : $receipt->received_at->max($environment->telemetry_last_received_at),
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
            DB::transaction(function () use ($claim, $code, $permanent): void {
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
        DB::transaction(function () use ($receiptId, $generation): void {
            $receipt = $this->queue->lockReceipt($receiptId);

            if ($receipt !== null && $receipt->generation === $generation && $receipt->status !== IngestStatus::Completed) {
                $this->queue->markFailed($receipt, $receipt->last_error_code ?? 'worker_interrupted');
            }
        }, attempts: 3);
    }
}
