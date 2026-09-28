<?php

declare(strict_types=1);

namespace App\Data\Deploy;

/** One setting that differs between two deploys' environment snapshots. */
final readonly class BuildChange
{
    /**
     * Create a new BuildChange instance.
     *
     * @param  string  $area  Where the setting lives: Runtime, Variables, Build variables, Workers, Resources or Environment file.
     * @param  string  $name  The setting, variable, worker or resource name.
     * @param  string  $kind  added, removed or changed
     * @param  string|null  $from  The earlier value, or null when it's secret or didn't exist.
     * @param  string|null  $to  The later value, or null when it's secret or no longer exists.
     */
    public function __construct(public string $area, public string $name, public string $kind, public ?string $from = null, public ?string $to = null) {}
}
