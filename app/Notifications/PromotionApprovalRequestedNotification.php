<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PromotionApprovalRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly int $buildId, private readonly string $source, private readonly string $target) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => 'deployment',
            'resource_id' => $this->buildId,
            'title' => str("Promotion to {$this->target} needs approval")->limit(255)->toString(),
            'message' => str("Review the tested release from {$this->source} before it is deployed.")->limit(500)->toString(),
            'status' => NotificationInbox::STATUS_INFO,
        ];
    }
}
