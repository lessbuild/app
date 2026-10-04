<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SaveLoadBalancer;
use App\Http\Requests\Infrastructure\LoadBalancerRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StoreLoadBalancerController
{
    /**
     * Add a load balancer.
     *
     * @param  LoadBalancerRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveLoadBalancer  $save
     * @return JsonResponse
     */
    public function __invoke(LoadBalancerRequest $request, #[CurrentUser] User $user, Project $project, SaveLoadBalancer $save): JsonResponse
    {
        $save->handle($project->account, $user, $request->loadBalancer());

        return response()->json(['redirect' => route('infrastructure.load-balancers', $project, false), 'message' => __('Load balancer added. Point its hostname at the proxy server.')]);
    }
}
