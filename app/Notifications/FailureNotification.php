<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class FailureNotification extends Notification
{
    use Queueable;

    public const CATEGORIES = [
        'deployment',
        'website',
        'server',
        'provider',
    ];

    public function __construct(
        private readonly string $category,
        private readonly int $resourceId,
        private readonly string $title,
        private readonly string $message,
        private readonly string $status = 'failed',
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{category: string, resource_id: int, title: string, message: string, status: string} */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'resource_id' => $this->resourceId,
            'title' => str($this->title)->limit(255)->toString(),
            'message' => str($this->message)->limit(500)->toString(),
            'status' => $this->status === 'healthy' ? 'healthy' : 'failed',
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
            'provider' => 'providers.show',
            default => null,
        };

        return $route ? route($route, $resourceId) : null;
    }
}
