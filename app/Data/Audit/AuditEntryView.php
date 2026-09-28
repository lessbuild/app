<?php

declare(strict_types=1);

namespace App\Data\Audit;

use Carbon\CarbonImmutable;

final readonly class AuditEntryView
{
    /**
     * Create a new AuditEntryView instance.
     *
     * One line of the audit log.
     *
     * @param  string  $id  The entry's ID.
     * @param  string  $actor  Who did it, by name, or "System" when no person did.
     * @param  ?string  $actorEmail  Their email, to tell apart people with the same name.
     * @param  string  $description  What happened, as a sentence.
     * @param  ?string  $ipAddress  Where the request came from, when it came from a request.
     * @param  ?string  $device  The browser and system it came from.
     * @param  CarbonImmutable  $at  When it happened.
     */
    public function __construct(
        public string $id,
        public string $actor,
        public ?string $actorEmail,
        public string $description,
        public ?string $ipAddress,
        public ?string $device,
        public CarbonImmutable $at,
    ) {}
}
