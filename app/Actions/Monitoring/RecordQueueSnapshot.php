<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\QueueSnapshot;
use App\Services\Monitoring\MonitorQueue;
use App\Services\Monitoring\QueueMonitorEvaluator;
use App\Support\Monitoring\QueueMonitorSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RecordQueueSnapshot
{
    /**
     * Records a queue report sent by a queue monitor's collector.
     *
     * @param  MonitorQueue  $queue  Locks the monitor and checks it still accepts reports.
     * @param  QueueMonitorEvaluator  $evaluation  Re-evaluates the monitor with the new report.
     */
    public function __construct(private readonly MonitorQueue $queue, private readonly QueueMonitorEvaluator $evaluation) {}

    /**
     * Stores a queue report after checking the monitor's key. Resending a snapshot ID with the same payload returns the
     * original receipt; reusing it for a different payload, or sending a second snapshot for the same moment, is a
     * conflict. Reports older than the latest are stored but not applied.
     *
     * @param  array<string, mixed>  $data
     * @return array{snapshot_id: string, replayed: bool, applied: bool, received_at: string}
     */
    public function handle(int $monitorId, string $tokenHash, array $data): array
    {
        return DB::transaction(function () use ($monitorId, $tokenHash, $data): array {
            $monitor = $this->queue->lockMonitor($monitorId);
            abort_unless($this->queue->eligible($monitor) && $monitor->type === 'queue' && is_string($monitor->queue_token_hash)
                && hash_equals($monitor->queue_token_hash, $tokenHash), 401, 'The queue key is invalid or the source is unavailable.');
            $observed = CarbonImmutable::parse($data['observed_at'], 'UTC');
            $values = ['observed_at' => $observed->format('Y-m-d H:i:s.u')];
            foreach (QueueMonitorSettings::METRICS as $metric) {
                $values[$metric] = $data[$metric] ?? null;
            }
            $hash = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
            $snapshotId = strtolower($data['snapshot_id']);
            $existing = $monitor->queueSnapshots()->where('snapshot_id', $snapshotId)->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless($existing->config_revision === $monitor->config_revision && hash_equals($existing->payload_hash, $hash),
                    409, 'This snapshot ID was used for a different payload or monitor configuration.');

                return $this->receipt($existing, true);
            }
            $now = CarbonImmutable::now('UTC');
            $latest = $monitor->queue_snapshot_id === null ? null : $monitor->queueSnapshots()->find($monitor->queue_snapshot_id);
            abort_if($latest !== null && $latest->observed_at->equalTo($observed),
                409, 'Use one snapshot ID per sample timestamp. A new sample must have a different timestamp.');
            if ($monitor->next_check_at?->lte($now)) {
                $this->evaluation->evaluate($monitor, $now);
            }
            $applied = $observed->gte($monitor->queue_started_at ?? $now) && ($latest === null || $observed->gt($latest->observed_at));
            $snapshot = $monitor->queueSnapshots()->make()->forceFill([
                ...$values, 'snapshot_id' => $snapshotId, 'payload_hash' => $hash,
                'config_revision' => $monitor->config_revision, 'applied' => $applied, 'received_at' => $now,
                'valid_until' => $observed->min($now)->addSeconds($monitor->queueThresholds()['report_timeout_seconds']),
            ]);
            $snapshot->save();
            if ($applied) {
                $monitor->forceFill(['queue_snapshot_id' => $snapshot->id])->save();
                $this->evaluation->evaluate($monitor, $now, recordSnapshot: true);
            }

            return $this->receipt($snapshot, false);
        }, attempts: 3);
    }

    /**
     * What the collector gets back: the snapshot ID, whether it was a replay, whether it was applied, and when it
     * arrived.
     *
     * @return array{snapshot_id: string, replayed: bool, applied: bool, received_at: string}
     */
    private function receipt(QueueSnapshot $snapshot, bool $replayed): array
    {
        return ['snapshot_id' => $snapshot->snapshot_id, 'replayed' => $replayed,
            'applied' => $snapshot->applied, 'received_at' => (string) $snapshot->received_at->toISOString()];
    }
}
