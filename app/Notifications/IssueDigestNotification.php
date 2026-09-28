<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The daily issue digest email. Sent synchronously by the digest command, which records the outcome. */
final class IssueDigestNotification extends Notification
{
    /**
     * The daily email summarising an account's issues for someone who asked for it.
     *
     * @param  array{account: string, from: CarbonImmutable, until: CarbonImmutable, open: int, critical: int, snoozed: int, new: list<array{title: string, location: string|null, project: string, severity: string, occurrences: int, at: string, url: string}>, resolved: list<array{title: string, location: string|null, project: string, severity: string, occurrences: int, at: string, url: string}>, active: bool}  $digest  The account's issue report for the period, built by IssueDigest with already-redacted titles.
     */
    public function __construct(public readonly array $digest) {}

    /**
     * Digests are email only; the issues pages already show the same information in the app.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * The open, critical and snoozed counts, then each new and resolved issue with a link, the period covered, and where
     * to turn the digest off.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__(':account issues: :new new, :resolved resolved', ['account' => $this->digest['account'], 'new' => count($this->digest['new']), 'resolved' => count($this->digest['resolved'])]))
            ->line(__(':open open (:critical critical), :snoozed snoozed.', ['open' => $this->digest['open'], 'critical' => $this->digest['critical'], 'snoozed' => $this->digest['snoozed']]));
        foreach (['new' => __('New'), 'resolved' => __('Resolved')] as $key => $heading) {
            if ($this->digest[$key] !== []) {
                $message->line('**'.$heading.'**');
                foreach ($this->digest[$key] as $issue) {
                    $message->line('['.$issue['title'].']('.$issue['url'].') · '.$issue['project'].' · '.$issue['severity'].' · '.trans_choice(':count event|:count events', $issue['occurrences'], ['count' => $issue['occurrences']]));
                }
            }
        }

        return $message
            ->line(__('Covers :from to :until UTC.', ['from' => $this->digest['from']->format('Y-m-d H:i'), 'until' => $this->digest['until']->format('Y-m-d H:i')]))
            ->action(__('Change digest emails'), route('settings.notifications'));
    }
}
