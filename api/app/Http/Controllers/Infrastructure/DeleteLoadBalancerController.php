<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteLoadBalancer;
use App\Models\LoadBalancer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteLoadBalancerController
{
    /**
     * Start removing a load balancer.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  LoadBalancer  $loadBalancer
     * @param  DeleteLoadBalancer  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, LoadBalancer $loadBalancer, DeleteLoadBalancer $delete): JsonResponse
    {
        $delete->handle($user, $loadBalancer);

        return response()->json(['redirect' => route('infrastructure.load-balancers', $project, false), 'message' => __('Removing the load balancer.')]);
    }
}
