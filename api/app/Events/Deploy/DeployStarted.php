<?php

declare(strict_types=1);

namespace App\Events\Deploy;

use App\Models\Build;
use Illuminate\Foundation\Events\Dispatchable;

/** A deploy started running on the server. */
final class DeployStarted
{
    use Dispatchable;

    /**
     * Create a new DeployStarted instance.
     *
     * @param  Build  $build  The deploy.
     */
    public function __construct(public readonly Build $build) {}
}
