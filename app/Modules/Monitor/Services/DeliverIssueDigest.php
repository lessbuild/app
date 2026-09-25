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
    public function __construct(
        private readonly IssueDigestReport $report,
        private readonly WorkspacePlanLimits $limits,
        private readonly IssueDigestSourceAccess $access,
    ) {}

    public function deliver(Workspace $workspace, User $recipient, CarbonImmutable $from, CarbonImmutable $until): string
    {
        $workspace = $workspace->fresh();
        $recipient = $workspace === null ? null : $this->eligibleRecipient($workspace, $recipient);
        if ($recipient === null) {
            return 'skipped';
        }
        $digest = $this->report->forRecipient($workspace, $recipient, $from, $until);
        if (! $digest['has_activity']) {
            return 'skipped';
        }

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
                'source_scope' => $digest['source_scope'],
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

        // Rebuild after claiming: retries and recipients never reuse another user's payload or stale permissions.
        $workspace = $workspace->fresh();
        $recipient = $workspace === null ? null : $this->eligibleRecipient($workspace, $recipient);
        if ($recipient === null) {
            $this->failed($delivery, 'recipient_unavailable');

            return 'skipped';
        }
        $digest = $this->report->forRecipient($workspace, $recipient, $from, $until);
        if (! $digest['has_activity']) {
            $this->failed($delivery, 'source_access_changed');

            return 'skipped';
        }
        $delivery->forceFill([
            'recipient_email' => $recipient->email,
            'new_count' => count($digest['new_issues']),
            'resolved_count' => count($digest['resolved_issues']),
            'open_count' => $digest['open_count'],
            'critical_open_count' => $digest['critical_open_count'],
            'source_scope' => $digest['source_scope'],
        ])->save();
        if (! $this->access->allowedScopes($workspace, $recipient, [$digest['source_scope']])[0]) {
            $this->failed($delivery, 'source_access_changed');

            return 'skipped';
        }
        unset($digest['source_scope']);

        try {
            $recipient->notifyNow(new IssueDigestNotification($digest));
        } catch (Throwable $exception) {
            report($exception);
            $this->failed($delivery, 'notification_failed');

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

    private function eligibleRecipient(Workspace $workspace, User $recipient): ?User
    {
        if (! $this->limits->issueDigestEnabled($workspace)) {
            return null;
        }
        $member = $workspace->members()->whereKey($recipient->getKey())->whereNotNull('email_verified_at')->first();
        if ($member === null) {
            return null;
        }
        $preference = $workspace->issueDigestPreferences()->where('user_id', $member->getKey())->first();
        $enabled = $preference === null
            ? $member->id === $workspace->owner_id
            : $preference->enabled && $preference->frequency === 'daily';

        return $enabled ? $member : null;
    }

    private function failed(IssueDigestDelivery $delivery, string $code): void
    {
        $delivery->forceFill([
            'status' => IssueDigestDelivery::STATUS_FAILED,
            'last_error_code' => $code,
            'failed_at' => CarbonImmutable::now('UTC'),
            'sending_started_at' => null,
        ])->save();
    }
}
