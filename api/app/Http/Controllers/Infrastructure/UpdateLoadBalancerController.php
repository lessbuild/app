<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SaveLoadBalancer;
use App\Http\Requests\Infrastructure\LoadBalancerRequest;
use App\Models\LoadBalancer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class UpdateLoadBalancerController
{
    /**
     * Save a load balancer.
     *
     * @param  LoadBalancerRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  LoadBalancer  $loadBalancer
     * @param  SaveLoadBalancer  $save
     * @return JsonResponse
     */
    public function __invoke(LoadBalancerRequest $request, #[CurrentUser] User $user, Project $project, LoadBalancer $loadBalancer, SaveLoadBalancer $save): JsonResponse
    {
        $save->handle($project->account, $user, $request->loadBalancer(), $loadBalancer);

        return response()->json(['redirect' => route('infrastructure.load-balancers', $project, false), 'message' => __('Load balancer saved.')]);
    }
}
