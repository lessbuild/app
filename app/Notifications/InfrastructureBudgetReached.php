<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/** An account's servers now cost 80% or all of its monthly infrastructure budget. Sent to its owners. */
final class InfrastructureBudgetReached extends InboxNotification
{
    /**
     * Create a new InfrastructureBudgetReached instance.
     *
     * @param  string  $accountId  The account.
     * @param  string  $accountName  Its name, for the message.
     * @param  int  $threshold  80 or 100 (percent of the budget).
     * @param  float  $cost  What the servers cost a month now, in USD.
     * @param  float  $budget  The monthly budget, in USD.
     * @param  string  $url  The costs page.
     */
    public function __construct(
        private readonly string $accountId,
        private readonly string $accountName,
        private readonly int $threshold,
        private readonly float $cost,
        private readonly float $budget,
        private readonly string $url,
    ) {}

    /**
     * Get the notification's delivery channels: email and the inbox.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Build the email, with a link to the costs page.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailWithAction(__('Review server costs'));
    }

    /**
     * Get the headline.
     *
     * @return string
     */
    protected function title(): string
    {
        return $this->threshold >= 100
            ? __(':account’s servers are over their monthly budget', ['account' => $this->accountName])
            : __(':account’s servers are at :percent% of their monthly budget', ['account' => $this->accountName, 'percent' => $this->threshold]);
    }

    /**
     * Get the cost against the budget.
     *
     * @return string
     */
    protected function body(): string
    {
        return __('Servers cost $:cost a month against a budget of $:budget. Idle servers are marked on the costs page.', ['cost' => number_format($this->cost, 2), 'budget' => number_format($this->budget, 2)]);
    }

    /**
     * Get the costs page.
     *
     * @return string
     */
    protected function url(): string
    {
        return $this->url;
    }

    /**
     * Get the account it's about.
     *
     * @return string|null
     */
    protected function accountId(): ?string
    {
        return $this->accountId;
    }
}
