<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\UsageAlertDelivery;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Notifications\UsageAlertNotification;
use App\Modules\Monitor\Services\Core\MonitorDeletionFence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeliverUsageAlert
{
    /**
     * @param  array{
     *     period_start: CarbonImmutable,
     *     period_end: CarbonImmutable,
     *     event_count: int,
     *     event_limit: int,
     *     percentage: int,
     *     state: 'healthy'|'warning'|'limit'
     * }  $summary
     */
    public function deliver(Workspace $workspace, User $recipient, array $summary, int $threshold): string
    {
        if (MonitorDeletionFence::workspaceIsFenced($workspace->getKey())) {
            return 'skipped';
        }

        $delivery = DB::connection('monitor')->transaction(function () use ($workspace, $recipient, $summary, $threshold): ?UsageAlertDelivery {
            if (MonitorDeletionFence::lockWorkspace($workspace->getKey())) {
                return null;
            }
            $now = CarbonImmutable::now('UTC');
            $delivery = UsageAlertDelivery::query()
                ->forWorkspace($workspace)
                ->forRecipient($recipient)
                ->where('period_start', $summary['period_start']->format('Y-m-d H:i:s.u'))
                ->where('threshold', $threshold)
                ->lockForUpdate()
                ->first();

            if ($delivery?->status === UsageAlertDelivery::STATUS_SENT) {
                return null;
            }

            if ($delivery?->status === UsageAlertDelivery::STATUS_SENDING
                && $delivery->sending_started_at?->greaterThan($now->subMinutes((int) config('monitor.beacon.usage_alerts.sending_lock_minutes', 15)))) {
                return null;
            }

            $values = [
                'workspace_id' => $workspace->id,
                'recipient_id' => $recipient->id,
                'recipient_email' => $recipient->email,
                'period_start' => $summary['period_start'],
                'period_end' => $summary['period_end'],
                'threshold' => $threshold,
                'status' => UsageAlertDelivery::STATUS_SENDING,
                'attempts' => ($delivery?->attempts ?? 0) + 1,
                'event_count' => $summary['event_count'],
                'event_limit' => $summary['event_limit'],
                'percentage' => $summary['percentage'],
                'last_error_code' => null,
                'sending_started_at' => $now,
                'sent_at' => null,
                'failed_at' => null,
            ];

            if ($delivery === null) {
                return UsageAlertDelivery::query()->create($values);
            }

            $delivery->forceFill($values)->save();

            return $delivery;
        }, attempts: 3);

        if ($delivery === null) {
            return 'skipped';
        }

        try {
            $recipient->notifyNow(new UsageAlertNotification($workspace, $summary, $threshold));
        } catch (Throwable $exception) {
            report($exception);
            $delivery->forceFill([
                'status' => UsageAlertDelivery::STATUS_FAILED,
                'last_error_code' => 'notification_failed',
                'failed_at' => CarbonImmutable::now('UTC'),
                'sending_started_at' => null,
            ])->save();

            return 'failed';
        }

        $delivery->forceFill([
            'status' => UsageAlertDelivery::STATUS_SENT,
            'last_error_code' => null,
            'failed_at' => null,
            'sending_started_at' => null,
            'sent_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return 'sent';
    }
}
