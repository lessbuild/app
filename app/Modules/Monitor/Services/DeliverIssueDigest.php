<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\IssueDigestDelivery;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Notifications\IssueDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeliverIssueDigest
{
    /**
     * @param  array<string, mixed>  $digest
     */
    public function deliver(Workspace $workspace, User $recipient, array $digest, CarbonImmutable $from, CarbonImmutable $until): string
    {
        $delivery = DB::connection('monitor')->transaction(function () use ($workspace, $recipient, $digest, $from, $until): ?IssueDigestDelivery {
            $now = CarbonImmutable::now('UTC');
            $delivery = IssueDigestDelivery::query()
                ->forWorkspace($workspace)
                ->forRecipient($recipient)
                ->where('period_start', $from->format('Y-m-d H:i:s.u'))
                ->where('period_end', $until->format('Y-m-d H:i:s.u'))
                ->lockForUpdate()
                ->first();

            if ($delivery?->status === IssueDigestDelivery::STATUS_SENT) {
                return null;
            }

            if ($delivery?->status === IssueDigestDelivery::STATUS_SENDING
                && $delivery->sending_started_at?->greaterThan($now->subMinutes(15))) {
                return null;
            }

            $values = [
                'workspace_id' => $workspace->id,
                'recipient_id' => $recipient->id,
                'recipient_email' => $recipient->email,
                'period_start' => $from,
                'period_end' => $until,
                'status' => IssueDigestDelivery::STATUS_SENDING,
                'attempts' => ($delivery?->attempts ?? 0) + 1,
                'new_count' => count($digest['new_issues'] ?? []),
                'resolved_count' => count($digest['resolved_issues'] ?? []),
                'open_count' => (int) ($digest['open_count'] ?? 0),
                'critical_open_count' => (int) ($digest['critical_open_count'] ?? 0),
                'last_error_code' => null,
                'sending_started_at' => $now,
                'failed_at' => null,
            ];

            if ($delivery === null) {
                return IssueDigestDelivery::query()->create($values);
            }

            $delivery->forceFill($values)->save();

            return $delivery;
        }, attempts: 3);

        if ($delivery === null) {
            return 'skipped';
        }

        try {
            $recipient->notifyNow(new IssueDigestNotification($digest));
        } catch (Throwable $exception) {
            report($exception);
            $delivery->forceFill([
                'status' => IssueDigestDelivery::STATUS_FAILED,
                'last_error_code' => 'notification_failed',
                'failed_at' => CarbonImmutable::now('UTC'),
                'sending_started_at' => null,
            ])->save();

            return 'failed';
        }

        $delivery->forceFill([
            'status' => IssueDigestDelivery::STATUS_SENT,
            'last_error_code' => null,
            'failed_at' => null,
            'sending_started_at' => null,
            'sent_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return 'sent';
    }
}
