<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteLoadBalancerNode;
use App\Models\LoadBalancer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteLoadBalancerNodeController
{
    /**
     * Take a server out from behind a load balancer.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  LoadBalancer  $loadBalancer
     * @param  string  $node
     * @param  DeleteLoadBalancerNode  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, LoadBalancer $loadBalancer, string $node, DeleteLoadBalancerNode $delete): RedirectResponse
    {
        $delete->handle($user, $loadBalancer->nodes()->findOrFail((int) $node));

        return to_route('infrastructure.load-balancers', $project)->with('status', __('Node removed.'));
    }
}
