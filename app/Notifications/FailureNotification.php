<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class FailureNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $category,
        private readonly int $resourceId,
        private readonly string $title,
        private readonly string $message,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{category: string, resource_id: int, title: string, message: string} */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'resource_id' => $this->resourceId,
            'title' => str($this->title)->limit(255)->toString(),
            'message' => str($this->message)->limit(500)->toString(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function destination(array $data): ?string
    {
        $resourceId = filter_var($data['resource_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (! $resourceId) {
            return null;
        }

        $route = match ($data['category'] ?? null) {
            'deployment' => 'builds.show',
            'website' => 'websites.show',
            'server' => 'servers.show',
            default => null,
        };

        return $route ? route($route, $resourceId) : null;
    }
}
