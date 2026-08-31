<?php

namespace App\Notifications;

final class NotificationInbox
{
    public const STATUS_FAILED = 'failed';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_INFO = 'info';

    public const CATEGORIES = [
        'deployment',
        'website',
        'server',
        'provider',
        'account',
    ];

    /** @param array<string, mixed> $data */
    public static function destination(array $data): ?string
    {
        $resourceId = filter_var($data['resource_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (! $resourceId) {
            return null;
        }

        return match ($data['category'] ?? null) {
            'deployment' => route('builds.show', $resourceId),
            'website' => route('websites.show', $resourceId),
            'server' => route('servers.show', $resourceId),
            'provider' => route('providers.show', $resourceId),
            'account' => route('account.index'),
            default => null,
        };
    }
}
