<?php

declare(strict_types=1);

namespace App\Support;

/** Reads the changelog (config/changelog.php, newest first) for the site page and the in-app "What's new". */
final class Changelog
{
    /**
     * Get the entries, newest first.
     *
     * @return list<array{date: string, title: string, changes: list<string>}>
     */
    public static function entries(): array
    {
        $entries = [];
        foreach ((array) config('changelog') as $entry) {
            if (is_array($entry) && is_string($entry['date'] ?? null) && is_string($entry['title'] ?? null)) {
                $entries[] = ['date' => $entry['date'], 'title' => $entry['title'], 'changes' => array_values(array_filter((array) ($entry['changes'] ?? []), 'is_string'))];
            }
        }

        return $entries;
    }

    /**
     * Count the entries newer than the day someone last looked (all of them when they never have).
     *
     * @param  string|null  $lastSeen  Y-m-d
     * @return int
     */
    public static function unseen(?string $lastSeen): int
    {
        return count(array_filter(self::entries(), fn (array $entry): bool => $lastSeen === null || $entry['date'] > $lastSeen));
    }

    /**
     * Get the newest entry's date.
     *
     * @return string|null
     */
    public static function latestDate(): ?string
    {
        return self::entries()[0]['date'] ?? null;
    }
}
