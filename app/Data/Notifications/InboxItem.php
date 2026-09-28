<?php

declare(strict_types=1);

namespace App\Data\Notifications;

use Carbon\CarbonImmutable;

final readonly class InboxItem
{
    /**
     * Create a new InboxItem instance.
     *
     * One notification in the inbox.
     *
     * @param  string  $id  The notification's ID, used to mark it read.
     * @param  string  $title  The headline.
     * @param  string  $body  The detail line.
     * @param  bool  $read  Whether the person has already seen it.
     * @param  CarbonImmutable  $at  When it was sent.
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $body,
        public bool $read,
        public CarbonImmutable $at,
    ) {}
}
