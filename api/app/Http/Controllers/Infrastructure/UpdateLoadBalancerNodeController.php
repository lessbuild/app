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

final class UpdateLoadBalancerNodeController
{
    /**
     * Save a node's port, weight and whether it receives traffic.
     *
     * @param  LoadBalancerNodeRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  LoadBalancer  $loadBalancer
     * @param  string  $node
     * @param  SaveLoadBalancerNode  $save
     * @return JsonResponse
     */
    public function __invoke(LoadBalancerNodeRequest $request, #[CurrentUser] User $user, Project $project, LoadBalancer $loadBalancer, string $node, SaveLoadBalancerNode $save): JsonResponse
    {
        $save->handle($user, $loadBalancer, $request->node(), $loadBalancer->nodes()->findOrFail((int) $node));

        return response()->json(['redirect' => route('infrastructure.load-balancers', $project, false), 'message' => __('Node saved.')]);
    }
}
