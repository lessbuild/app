<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SaveLoadBalancerNode;
use App\Http\Requests\Infrastructure\LoadBalancerNodeRequest;
use App\Models\LoadBalancer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreLoadBalancerNodeController
{
    /**
     * Puts a server behind a load balancer.
     */
    public function __invoke(LoadBalancerNodeRequest $request, #[CurrentUser] User $user, Project $project, LoadBalancer $loadBalancer, SaveLoadBalancerNode $save): RedirectResponse
    {
        $save->handle($user, $loadBalancer, $request->node());

        return to_route('infrastructure.load-balancers', $project)->with('status', __('Node added.'));
    }
}
