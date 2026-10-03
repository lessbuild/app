<?php

declare(strict_types=1);

namespace App\Data\Infrastructure;

/** The website fields a form sets, normalised. */
final readonly class WebsiteAttributes
{
    /**
     * Build the website columns a create or update form sets, trimmed and cast, with the defaults new websites get
     * (five releases kept, health monitoring every five minutes, alert after three failures).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function from(array $data): array
    {
        $description = trim((string) ($data['description'] ?? ''));

        return [
            'name' => trim((string) $data['name']),
            'description' => $description !== '' ? $description : null,
            'url' => (string) $data['url'],
            'env_file' => (string) ($data['env_file'] ?? ''),
            'release_retention' => (int) ($data['release_retention'] ?? 5),
            'health_check_enabled' => (bool) ($data['health_check_enabled'] ?? false),
            'health_check_path' => (string) ($data['health_check_path'] ?? '/'),
            'health_monitoring_enabled' => (bool) ($data['health_monitoring_enabled'] ?? true),
            'self_healing' => (bool) ($data['self_healing'] ?? false),
            'health_check_interval_minutes' => (int) ($data['health_check_interval_minutes'] ?? 5),
            'health_failure_threshold' => (int) ($data['health_failure_threshold'] ?? 3),
        ];
    }
}
