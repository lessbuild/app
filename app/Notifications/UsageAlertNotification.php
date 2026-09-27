<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

/** Monitoring's monthly event allowance is 80% used, or used up. Sent by email and to the inbox. */
final class UsageAlertNotification extends InboxNotification
{
    /**
     * Tells an account owner that Monitoring events for the month crossed a threshold of the plan's allowance.
     *
     * @param  Account  $account  The account.
     * @param  array{period_start: CarbonImmutable, period_end: CarbonImmutable, event_count: int, event_limit: int|null, percentage: int, crossed: list<int>}  $usage  The account's Monitoring usage for the current period.
     * @param  int  $threshold  The percentage crossed (such as 80 or 100).
     */
    public function __construct(private readonly Account $account, private readonly array $usage, private readonly int $threshold) {}

    /**
     * By email, because telemetry stops being accepted at 100%, and in the inbox.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * The usage, when the allowance resets, and a link to plans.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->body())
            ->line(__('The allowance resets on :date.', ['date' => $this->usage['period_end']->toFormattedDayDateString()]))
            ->action(__('Review plans'), $this->url());
    }

    /**
     * How much of the allowance is used, or that all of it is.
     */
    protected function title(): string
    {
        return $this->threshold >= 100
            ? __(':account has used all of this month’s Monitoring events', ['account' => $this->account->name])
            : __(':account has used :percent% of this month’s Monitoring events', ['account' => $this->account->name, 'percent' => $this->threshold]);
    }

    /**
     * The event count against the limit and what happens at 100%.
     */
    protected function body(): string
    {
        $counts = ['count' => number_format($this->usage['event_count']), 'limit' => number_format((int) $this->usage['event_limit'])];

        return $this->threshold >= 100
            ? __(':count of :limit events. New telemetry is refused until the month ends or you upgrade.', $counts)
            : __(':count of :limit events. At 100% new telemetry is refused until the month ends.', $counts);
    }

    /**
     * The billing page, to upgrade.
     */
    protected function url(): string
    {
        return route('account.billing');
    }

    /**
     * The account the usage belongs to.
     */
    protected function accountId(): string
    {
        return $this->account->id;
    }
}
