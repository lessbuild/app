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

    /**
     * One server's line on the costs page.
     *
     * @param  Server  $server  The server.
     * @param  ?float  $monthly  What it costs per month, when known.
     * @param  ?float  $averageCpu  Its average CPU use over the last hour, as a percentage; null without samples.
     * @param  int  $websites  How many websites run on it.
     * @param  bool  $idle  Whether it looks unused: no websites, or at least six samples in the last hour averaging under 10% CPU.
     * @param  list<string>  $projects  names of projects whose websites run on the server
     */
    public function __construct(
        public Server $server,
        public ?float $monthly,
        public ?float $averageCpu,
        public int $websites,
        public bool $idle,
        public array $projects,
    ) {}

    /**
     * How the cost splits across projects: all to one project, shared between several, or not attributed to any.
     *
     * @return string
     */
    public function attribution(): string
    {
        return match (count($this->projects)) {
            0 => self::UNALLOCATED,
            1 => self::DIRECT,
            default => self::SHARED,
        };
    }
}
