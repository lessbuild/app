<?php

declare(strict_types=1);

namespace App\Events\Deploy;

use App\Models\Build;
use Illuminate\Foundation\Events\Dispatchable;

/** A deploy finished: succeeded, failed or was cancelled. */
final class DeployFinished
{
    use Dispatchable;

    /**
     * Create a new DeployFinished instance.
     *
     * @param  Build  $build  The deploy.
     */
    public function __construct(public readonly Build $build) {}
}
