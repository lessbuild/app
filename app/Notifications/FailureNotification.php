<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class FailureNotification extends Notification
{
    use Queueable;

    public const CATEGORIES = NotificationInbox::CATEGORIES;

    /**
     * Capture an incident category, resource, display text, and failure or recovery state.
     *
     * @param  string  $category  Inbox category used to resolve the related application page.
     * @param  int  $resourceId  Identifier of the resource associated with this incident.
     * @param  string  $title  Incident headline, bounded to 255 characters in the stored payload.
     * @param  string  $message  Incident explanation, bounded to 500 characters in the stored payload.
     * @param  string  $status  The literal healthy represents recovery; other values become failed.
     */
    public function __construct(
        private readonly string $category,
        private readonly int $resourceId,
        private readonly string $title,
        private readonly string $message,
        private readonly string $status = 'failed',
    ) {}

    /**
     * Deliver this notification through Laravel's database channel.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return list<string> The single database channel used for this notification.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Build bounded incident inbox data and normalize its state to healthy for recovery or failed otherwise.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return array{category: string, resource_id: int, title: string, message: string, status: string} Category, resource, bounded display text, and normalized incident status.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'resource_id' => $this->resourceId,
            'title' => str($this->title)->limit(255)->toString(),
            'message' => str($this->message)->limit(500)->toString(),
            'status' => $this->status === NotificationInbox::STATUS_HEALTHY
                ? NotificationInbox::STATUS_HEALTHY
                : NotificationInbox::STATUS_FAILED,
        ];
    }

    /**
     * Resolve the related application destination from a persisted notification payload.
     *
     * @param  array<string, mixed>  $data  Persisted inbox payload containing a category, resource_id, and optional report_id.
     * @return string|null The application URL for a recognized resource, or null for invalid or unsupported payloads.
     */
    public static function destination(array $data): ?string
    {
        return NotificationInbox::destination($data);
    }
}
