<?php

declare(strict_types=1);

namespace App\Services\Telemetry;

use App\Enums\IngestStatus;
use App\Jobs\Telemetry\ProcessQueuedTelemetry;
use App\Models\Account;
use App\Models\Environment;
use App\Models\IngestReceipt;
use App\Models\Project;
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

    /**
     * Queues processing for a receipt inside the transaction that stored it, and remembers the job's UUID (on the
     * database queue) so a lost job can be noticed and replaced.
     *
     * @param  IngestReceipt  $receipt
     * @return void
     */
    public function dispatch(IngestReceipt $receipt): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Telemetry dispatch must share the receipt transaction.');
        }

        $receipt->refresh();
        $connection = $this->connection();
        $job = (new ProcessQueuedTelemetry($receipt->id, $receipt->generation))
            ->onConnection($connection)->onQueue(self::NAME)->beforeCommit();
        $jobId = Queue::connection($connection)->push($job, '', self::NAME);

        if (config('queue.connections.'.$connection.'.driver') === 'database') {
            $uuid = DB::table('jobs')->where('id', $jobId)->value('job_uuid');

            if (! is_string($uuid) || $uuid === '') {
                throw new LogicException('The telemetry job must have a stable identifier.');
            }

            $receipt->forceFill(['queue_job_uuid' => $uuid])->save();
        }

        $receipt->refresh();
    }

    /**
     * The telemetry queue connection, which must be the primary database's jobs table with a long enough reservation (or
     * sync in tests), so jobs commit with their receipts.
     *
     * @return string
     */
    public function connection(): string
    {
        $name = config('monitoring.telemetry.queue_connection');

        if (! is_string($name) || $name === '') {
            throw new LogicException('A telemetry queue connection must be configured.');
        }

        $configuration = config('queue.connections.'.$name, []);
        $driver = $configuration['driver'] ?? null;

        if ($driver !== 'sync' && ($driver !== 'database'
            || ! in_array($configuration['connection'] ?? null, [null, DB::getDefaultConnection()], true)
            || ($configuration['table'] ?? null) !== 'jobs'
            || (int) ($configuration['retry_after'] ?? 0) < self::RETRY_AFTER)) {
            throw new LogicException('Telemetry requires the primary database jobs queue with a reservation of at least 180 seconds, or the sync driver.');
        }

        return $name;
    }

    /**
     * Lock order matches ingestion: account → project → environment → receipt.
     *
     * @param  string  $receiptId
     * @return IngestReceipt|null
     */
    public function lockReceipt(string $receiptId): ?IngestReceipt
    {
        $hint = IngestReceipt::query()->select(['id', 'account_id', 'environment_id'])->find($receiptId);
        if ($hint !== null) {
            Account::query()->lockForUpdate()->find($hint->account_id);
        }
        $environment = $hint?->environment_id === null ? null : Environment::query()->find($hint->environment_id);
        if ($environment !== null) {
            Project::query()->lockForUpdate()->find($environment->project_id);
            Environment::query()->lockForUpdate()->find($environment->id);
        }

        return IngestReceipt::query()->lockForUpdate()->find($receiptId);
    }

    /**
     * Queues a receipt again under a new generation: a failed one when someone asks, or (during recovery) a stuck one
     * whose job is gone. Receipts without a kept payload, or past their attempts, are marked failed instead.
     *
     * @param  string  $receiptId
     * @param  bool  $recovery
     * @return bool
     */
    public function retry(string $receiptId, bool $recovery = false): bool
    {
        return DB::transaction(function () use ($receiptId, $recovery): bool {
            $receipt = $this->lockReceipt($receiptId);

            if ($receipt === null || $receipt->status === IngestStatus::Completed) {
                return false;
            }

            if ($recovery) {
                if (! in_array($receipt->status, [IngestStatus::Queued, IngestStatus::Retrying, IngestStatus::Processing], true)
                    || $receipt->next_attempt_at?->isFuture()
                    || ($receipt->queue_job_uuid !== null && DB::table('jobs')->where('queue', self::NAME)
                        ->where('job_uuid', $receipt->queue_job_uuid)->exists())) {
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
                'queue_job_uuid' => null,
                'next_attempt_at' => now('UTC'),
            ])->save();
            $this->dispatch($receipt);

            return true;
        }, attempts: 3);
    }

    /**
     * Requeues up to `$limit` receipts that are due but have no job, and returns how many.
     *
     * @param  int  $limit
     * @return int
     */
    public function recover(int $limit = 100): int
    {
        $this->connection();
        $receipts = IngestReceipt::query()
            ->whereIn('status', [IngestStatus::Queued, IngestStatus::Retrying, IngestStatus::Processing])
            ->where('next_attempt_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('jobs')
                ->where('jobs.queue', self::NAME)->whereColumn('jobs.job_uuid', 'ingest_receipts.queue_job_uuid'))
            ->orderBy('next_attempt_at')->orderBy('id')
            ->limit(max(1, min(1000, $limit)))->pluck('id');

        return $receipts->filter(fn (string $id): bool => $this->retry($id, recovery: true))->count();
    }

    /**
     * Marks the receipt failed with an error code.
     *
     * @param  IngestReceipt  $receipt
     * @param  string  $code
     * @return void
     */
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
