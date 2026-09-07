<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PromotionApprovalRequestedNotification extends Notification
{
    use Queueable;

    /**
     * Capture the promotion build and source/target labels for an approval request.
     *
     * @param  int  $buildId  Promotion build awaiting approval.
     * @param  string  $source  Source environment label displayed to approvers.
     * @param  string  $target  Target environment label displayed to approvers.
     */
    public function __construct(private readonly int $buildId, private readonly string $source, private readonly string $target) {}

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
     * Build bounded deployment inbox data linking the promotion build to its target environment approval request.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return array{category: string, resource_id: int, title: string, message: string, status: string} Deployment-category inbox data with the build resource ID and informational approval text.
     */
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
