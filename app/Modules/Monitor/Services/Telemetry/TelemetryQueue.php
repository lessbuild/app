<?php

namespace App\Modules\Monitor\Services\Telemetry;

use App\Modules\Monitor\Data\Telemetry\IngestStatus;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\IngestReceipt;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;

final class TelemetryQueue
{
    public const NAME = 'telemetry';

    public const TIMEOUT = 60;

    public const RETRY_AFTER = 180;

    public const MAX_ATTEMPTS = 5;

    public const BACKOFF = [5, 30, 120, 300];

    public function dispatch(IngestReceipt $receipt): void
    {
        if (DB::connection('monitor')->transactionLevel() === 0) {
            throw new LogicException('Telemetry dispatch must share the receipt transaction.');
        }

        $receipt->refresh();
        $connection = $this->connection();
        $job = (new ProcessQueuedTelemetry($receipt->id, $receipt->generation))
            ->onConnection($connection)->onQueue(self::NAME)->beforeCommit();
        $jobId = Queue::connection($connection)->push($job, '', self::NAME);

        if (config('queue.connections.'.$connection.'.driver') === 'database') {
            $uuid = DB::connection('monitor')->table('jobs')->where('id', $jobId)->value('telemetry_uuid');

            if (! is_string($uuid) || $uuid === '') {
                throw new LogicException('The telemetry job must have a stable identifier.');
            }

            $receipt->forceFill(['queue_job_id' => $jobId, 'queue_job_uuid' => $uuid])->save();
        }

        $receipt->refresh();
    }

    public function connection(): string
    {
        $name = config('monitor.beacon.telemetry.queue_connection');

        if (! is_string($name) || $name === '') {
            throw new LogicException('A telemetry queue connection must be configured.');
        }

        $configuration = config('queue.connections.'.$name, []);
        $driver = $configuration['driver'] ?? null;

        if ($driver !== 'sync' && ($driver !== 'database'
            || ($configuration['connection'] ?? null) !== 'monitor'
            || ($configuration['table'] ?? null) !== 'jobs'
            || (int) ($configuration['retry_after'] ?? 0) < self::RETRY_AFTER)) {
            throw new LogicException('Telemetry requires the primary database jobs queue with a reservation of at least 180 seconds, or the sync driver.');
        }

        return $name;
    }

    /** Lock order matches application archival and ingestion, including accepted deliveries on archived sources. */
    public function lockReceipt(string $receiptId): ?IngestReceipt
    {
        $hint = IngestReceipt::query()->select(['id', 'environment_id'])->find($receiptId);
        $environment = $hint?->environment_id === null
            ? null
            : Environment::withTrashed()->find($hint->environment_id);

        if ($environment !== null) {
            $application = Application::withTrashed()->find($environment->application_id);
            if ($application !== null) {
                Workspace::query()->lockForUpdate()->find($application->workspace_id);
            }
            Application::withTrashed()->lockForUpdate()->find($environment->application_id);
            Environment::withTrashed()->lockForUpdate()->find($environment->id);
        }

        return IngestReceipt::query()->lockForUpdate()->find($receiptId);
    }

    public function retry(string $receiptId, bool $recovery = false): bool
    {
        return DB::connection('monitor')->transaction(function () use ($receiptId, $recovery): bool {
            $receipt = $this->lockReceipt($receiptId);

            if ($receipt === null || $receipt->status === IngestStatus::Completed) {
                return false;
            }

            if ($recovery) {
                if (! in_array($receipt->status, [IngestStatus::Queued, IngestStatus::Retrying, IngestStatus::Processing], true)
                    || $receipt->next_attempt_at?->isFuture()
                    || ($receipt->queue_job_uuid !== null && DB::connection('monitor')->table('jobs')->where('queue', self::NAME)
                        ->where('telemetry_uuid', $receipt->queue_job_uuid)->exists())) {
                    return false;
                }

                if ($receipt->processing_attempts >= self::MAX_ATTEMPTS) {
                    $this->markFailed($receipt, 'worker_interrupted');

                    return false;
                }
            } elseif ($receipt->status !== IngestStatus::Failed) {
                return false;
            }

            if (! $receipt->ingestPayload()->exists()) {
                $this->markFailed($receipt, 'payload_unavailable');

                return false;
            }

            $receipt->forceFill([
                'status' => IngestStatus::Queued,
                'generation' => $receipt->generation + 1,
                'processing_attempts' => $recovery ? $receipt->processing_attempts : 0,
                'recovery_count' => $receipt->recovery_count + ($recovery ? 1 : 0),
                'processing_token' => null,
                'processing_started_at' => null,
                'failed_at' => null,
                'last_error_code' => null,
                'queue_job_id' => null,
                'queue_job_uuid' => null,
                'next_attempt_at' => now('UTC'),
            ])->save();
            $this->dispatch($receipt);

            return true;
        }, attempts: 3);
    }

    public function recover(int $limit = 100): int
    {
        $this->connection();
        $receipts = IngestReceipt::query()
            ->whereIn('status', [IngestStatus::Queued, IngestStatus::Retrying, IngestStatus::Processing])
            ->where('next_attempt_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('jobs')
                ->where('jobs.queue', self::NAME)->whereColumn('jobs.telemetry_uuid', 'ingest_receipts.queue_job_uuid'))
            ->orderBy('next_attempt_at')->orderBy('id')
            ->limit(max(1, min(1000, $limit)))->pluck('id');

        return $receipts->filter(fn (string $id): bool => $this->retry($id, recovery: true))->count();
    }

    public function markFailed(IngestReceipt $receipt, string $code): void
    {
        $receipt->forceFill([
            'status' => IngestStatus::Failed,
            'failed_at' => now('UTC'),
            'last_error_code' => $code,
            'next_attempt_at' => null,
            'processing_token' => null,
        ])->save();
    }
}
