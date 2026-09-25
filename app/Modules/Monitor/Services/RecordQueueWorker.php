<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\QueueWorker;
use App\Modules\Monitor\Services\Core\MonitorDeletionFence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RecordQueueWorker
{
    public function __construct(private readonly MonitorQueue $queue, private readonly EvaluateQueueMonitor $evaluation) {}

    /** @param array{worker_id: string, sequence: int, status: string, job_id?: ?string} $data
     * @return array{worker_id: string, sequence: int, status: string, replayed: bool, received_at: string}
     */
    public function record(int $monitorId, string $tokenHash, array $data): array
    {
        return DB::connection('monitor')->transaction(function () use ($monitorId, $tokenHash, $data): array {
            $monitor = $this->queue->lockMonitor($monitorId);
            abort_unless($this->queue->eligible($monitor) && $monitor->type === 'queue' && is_string($monitor->queue_token_hash)
                && hash_equals($monitor->queue_token_hash, $tokenHash), 401, 'The queue key is invalid or the source is unavailable.');
            abort_if(MonitorDeletionFence::lockWorkspace($monitor->environment->application->workspace_id), 410, 'This Monitor workspace is being deleted.');
            $workerId = strtolower($data['worker_id']);
            $jobId = isset($data['job_id']) ? strtolower($data['job_id']) : null;
            $worker = $monitor->queueWorkers()->where('worker_id', $workerId)->lockForUpdate()->first();
            if ($worker !== null) {
                abort_unless($worker->config_revision === $monitor->config_revision, 409, 'Use a new worker ID after the monitor configuration changes.');
                abort_if($data['sequence'] < $worker->last_sequence, 409, 'This worker sequence is older than the latest heartbeat.');
                if ($data['sequence'] === $worker->last_sequence) {
                    abort_unless($worker->status === $data['status'] && $worker->job_id === $jobId,
                        409, 'This sequence was already used for a different worker signal.');

                    return $this->receipt($worker, true);
                }
            }
            $now = CarbonImmutable::now('UTC');
            if ($monitor->next_check_at?->lte($now)) {
                $this->evaluation->evaluate($monitor, $now);
            }
            $live = $this->evaluation->liveWorkers($monitor, $now);
            if ($worker !== null) {
                $live->whereKeyNot($worker->id);
            }
            abort_if($data['status'] !== 'stopped' && $live->count() >= EvaluateQueueMonitor::MAX_LIVE_WORKERS,
                429, 'This queue already has 100 live workers.');
            $started = $data['status'] === 'busy'
                ? ($worker?->status === 'busy' && $worker->job_id === $jobId ? $worker->job_started_at : $now) : null;
            $worker ??= $monitor->queueWorkers()->make();
            $worker->forceFill(['worker_id' => $workerId, 'config_revision' => $monitor->config_revision,
                'last_sequence' => $data['sequence'], 'status' => $data['status'], 'job_id' => $jobId,
                'job_started_at' => $started, 'last_seen_at' => $now])->save();
            $this->evaluation->evaluate($monitor, $now);

            return $this->receipt($worker, false);
        }, attempts: 3);
    }

    /** @return array{worker_id: string, sequence: int, status: string, replayed: bool, received_at: string} */
    private function receipt(QueueWorker $worker, bool $replayed): array
    {
        return ['worker_id' => $worker->worker_id, 'sequence' => $worker->last_sequence, 'status' => $worker->status,
            'replayed' => $replayed, 'received_at' => $worker->last_seen_at->toISOString()];
    }
}
