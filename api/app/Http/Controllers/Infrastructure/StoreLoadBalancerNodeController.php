<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SaveLoadBalancerNode;
use App\Http\Requests\Infrastructure\LoadBalancerNodeRequest;
use App\Models\LoadBalancer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StoreLoadBalancerNodeController
{
    /**
     * Put a server behind a load balancer.
     *
     * @param  LoadBalancerNodeRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  LoadBalancer  $loadBalancer
     * @param  SaveLoadBalancerNode  $save
     * @return JsonResponse
     */
    public function __invoke(LoadBalancerNodeRequest $request, #[CurrentUser] User $user, Project $project, LoadBalancer $loadBalancer, SaveLoadBalancerNode $save): JsonResponse
    {
        $save->handle($user, $loadBalancer, $request->node());

        return response()->json(['redirect' => route('infrastructure.load-balancers', $project, false), 'message' => __('Node added.')]);
    }
}
