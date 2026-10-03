<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A site's weekly or monthly analytics report or CSV export, or a traffic alert, by email. The same text goes to Slack
 * (except CSV exports, which are email only).
 */
final class AnalyticsSiteReportNotification extends Notification
{
    /**
     * Create a new AnalyticsSiteReportNotification instance.
     *
     * @param  string  $subject  The email's subject.
     * @param  list<string>  $lines  The message, a line each.
     * @param  string  $url  Where to see the full report.
     * @param  array{name: string, csv: string}|null  $attachment  A CSV to attach.
     */
    public function __construct(public readonly string $subject, public readonly array $lines, public readonly string $url, public readonly ?array $attachment = null) {}

    /**
     * Get the notification's delivery channels: email only.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the email: the lines, a button to the report, and how to stop it.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->subject);
        foreach ($this->lines as $line) {
            $message->line($line);
        }

        if ($this->attachment !== null) {
            $message->attachData($this->attachment['csv'], $this->attachment['name'], ['mime' => 'text/csv']);
        }

        return $message->action(__('Open the report'), $this->url)
            ->line(__('You get this because someone added your address in the site’s analytics settings. Ask them to remove it to stop.'));
    }
}
