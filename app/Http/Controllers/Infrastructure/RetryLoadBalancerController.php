<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RetryLoadBalancer;
use App\Models\LoadBalancer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RetryLoadBalancerController
{
    /**
     * Tries a failed load-balancer change again.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  LoadBalancer  $loadBalancer
     * @param  RetryLoadBalancer  $retry
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, LoadBalancer $loadBalancer, RetryLoadBalancer $retry): RedirectResponse
    {
        $retry->handle($user, $loadBalancer);

        return to_route('infrastructure.load-balancers', $project)->with('status', __('Trying again.'));
    }
}
