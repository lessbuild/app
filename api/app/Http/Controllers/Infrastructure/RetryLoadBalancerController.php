<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RetryLoadBalancer;
use App\Models\LoadBalancer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class RetryLoadBalancerController
{
    /**
     * Try a failed load-balancer change again.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  LoadBalancer  $loadBalancer
     * @param  RetryLoadBalancer  $retry
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, LoadBalancer $loadBalancer, RetryLoadBalancer $retry): JsonResponse
    {
        $retry->handle($user, $loadBalancer);

        return response()->json(['redirect' => route('infrastructure.load-balancers', $project, false), 'message' => __('Trying again.')]);
    }
}
