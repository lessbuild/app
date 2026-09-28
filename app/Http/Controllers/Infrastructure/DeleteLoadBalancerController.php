<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteLoadBalancer;
use App\Models\LoadBalancer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteLoadBalancerController
{
    /**
     * Starts removing a load balancer.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  LoadBalancer  $loadBalancer
     * @param  DeleteLoadBalancer  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, LoadBalancer $loadBalancer, DeleteLoadBalancer $delete): RedirectResponse
    {
        $delete->handle($user, $loadBalancer);

        return to_route('infrastructure.load-balancers', $project)->with('status', __('Removing the load balancer.'));
    }
}
