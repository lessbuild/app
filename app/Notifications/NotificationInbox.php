<?php

namespace App\Notifications;

final class NotificationInbox
{
    public const STATUS_FAILED = 'failed';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_INFO = 'info';

    public const STATUSES = [
        self::STATUS_FAILED,
        self::STATUS_HEALTHY,
        self::STATUS_INFO,
    ];

    public const CATEGORIES = [
        'deployment',
        'website',
        'server',
        'provider',
        'recipe',
        'gallery',
        'account',
    ];

    /**
     * Validate notification resource identifiers and map supported categories to their application destinations.
     *
     * @param  array<string, mixed>  $data  Persisted inbox payload containing a category, resource_id, and optional report_id.
     * @return string|null The related application URL, optionally with a report anchor, or null for an invalid resource or unsupported category.
     */
    public static function destination(array $data): ?string
    {
        $resourceId = filter_var($data['resource_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (! $resourceId) {
            return null;
        }
        $reportId = filter_var($data['report_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return match ($data['category'] ?? null) {
            'deployment' => route('builds.show', $resourceId),
            'website' => route('websites.show', $resourceId),
            'server' => route('servers.show', $resourceId),
            'provider' => route('providers.show', $resourceId),
            'recipe' => route('gallery.reports.index', ['status' => 'all', 'report' => $resourceId])."#report-{$resourceId}",
            'gallery' => $reportId
                ? route('gallery.report.status', $reportId)
                : route('gallery.show', $resourceId),
            'account' => route('account.index'),
            default => null,
        };
    }
}
