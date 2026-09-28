<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\IssueDigestDelivery;
use App\Models\UsageAlertDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends a periodic email at most once per ledger key. A key already sent is skipped; one still sending is skipped
 * for 15 minutes (another run may be mid-send), then retried; a failed send is retried on the next run.
 */
final class EmailDeliveryLedger
{
    public const STALE_MINUTES = 15;

    /**
     * Sends one ledgered email: skips it when already sent or being sent by another run (for up to 15 minutes),
     * otherwise marks it sending, sends, and records sent or failed. Returns what happened.
     *
     * @param  class-string<UsageAlertDelivery|IssueDigestDelivery>  $model
     * @param  array<string, string|int>  $key  timestamps as 'Y-m-d H:i:s.u' strings
     * @param  array<string, mixed>  $values
     * @param  callable(): void  $send
     * @return 'sent'|'skipped'|'failed'
     */
    public function send(string $model, array $key, array $values, callable $send): string
    {
        $delivery = DB::transaction(function () use ($model, $key, $values): UsageAlertDelivery|IssueDigestDelivery|null {
            $now = CarbonImmutable::now('UTC');
            $existing = $model::query()->where($key)->lockForUpdate()->first();
            if ($existing?->status === 'sent' || ($existing?->status === 'sending' && $existing->sending_started_at?->greaterThan($now->subMinutes(self::STALE_MINUTES)))) {
                return null;
            }
            $delivery = $existing ?? new $model;
            $delivery->forceFill([...$key, ...$values, 'status' => 'sending', 'attempts' => ($existing->attempts ?? 0) + 1, 'last_error_code' => null, 'sending_started_at' => $now])->save();

            return $delivery;
        }, attempts: 3);
        if ($delivery === null) {
            return 'skipped';
        }

        try {
            $send();
        } catch (Throwable $exception) {
            report($exception);
            $delivery->forceFill(['status' => 'failed', 'last_error_code' => 'notification_failed', 'sending_started_at' => null])->save();

            return 'failed';
        }
        $delivery->forceFill(['status' => 'sent', 'sending_started_at' => null, 'sent_at' => CarbonImmutable::now('UTC')])->save();

        return 'sent';
    }
}
