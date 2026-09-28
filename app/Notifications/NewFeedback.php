<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Feedback;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/** Someone sent feedback. Sent to platform admins. */
final class NewFeedback extends InboxNotification
{
    /**
     * Create a new NewFeedback instance.
     *
     * @param  Feedback  $feedback  The feedback.
     */
    public function __construct(private readonly Feedback $feedback) {}

    /**
     * Get the delivery channels: email and the inbox.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Build the email with a link to the feedback.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailWithAction(__('Read feedback'));
    }

    /**
     * Get the headline, naming what kind of feedback it is.
     *
     * @return string
     */
    protected function title(): string
    {
        return __('New feedback: :kind', ['kind' => __(Feedback::KINDS[$this->feedback->kind] ?? $this->feedback->kind)]);
    }

    /**
     * Get the start of the message.
     *
     * @return string
     */
    protected function body(): string
    {
        return Str::limit($this->feedback->message, 140);
    }

    /**
     * Get the admin panel's feedback page.
     *
     * @return string
     */
    protected function url(): string
    {
        return route('admin.feedback');
    }
}
