<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteLoadBalancerNode;
use App\Models\LoadBalancer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

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
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, LoadBalancer $loadBalancer, string $node, DeleteLoadBalancerNode $delete): JsonResponse
    {
        $delete->handle($user, $loadBalancer->nodes()->findOrFail((int) $node));

        return response()->json(['redirect' => route('infrastructure.load-balancers', $project, false), 'message' => __('Node removed.')]);
    }
}
