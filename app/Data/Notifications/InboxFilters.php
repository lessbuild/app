<?php

declare(strict_types=1);

namespace App\Data\Notifications;

/** What the inbox is narrowed to. */
final readonly class InboxFilters
{
    /**
     * Create a new InboxFilters instance.
     *
     * @param  bool  $unreadOnly  Only notifications not yet read.
     * @param  string|null  $type  One notification class the person has received.
     * @param  string|null  $search  Words to find in the title or text.
     */
    public function __construct(public bool $unreadOnly = false, public ?string $type = null, public ?string $search = null) {}
}
