<?php

declare(strict_types=1);

namespace App\Events\Deploy;

use App\Models\Build;
use Illuminate\Foundation\Events\Dispatchable;

/** A deploy is waiting for someone to approve it. */
final class DeployAwaitingApproval
{
    use Dispatchable;

    /**
     * Create a new DeployAwaitingApproval instance.
     *
     * @param  Build  $build  The deploy.
     */
    public function __construct(public readonly Build $build) {}
}
