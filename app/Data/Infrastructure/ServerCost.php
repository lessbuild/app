<?php

declare(strict_types=1);

namespace App\Data\Infrastructure;

use App\Models\Server;

/** One server on the costs page: what it costs, how busy it is, and which projects use it. */
final readonly class ServerCost
{
    public const DIRECT = 'direct';

    public const SHARED = 'shared';

    public const UNALLOCATED = 'unallocated';

    /** @param list<string> $projects names of projects whose websites run on the server */
    public function __construct(
        public Server $server,
        public ?float $monthly,
        public ?float $averageCpu,
        public int $websites,
        public bool $idle,
        public array $projects,
    ) {}

    public function attribution(): string
    {
        return match (count($this->projects)) {
            0 => self::UNALLOCATED,
            1 => self::DIRECT,
            default => self::SHARED,
        };
    }
}
