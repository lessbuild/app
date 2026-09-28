<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SaveLoadBalancer;
use App\Http\Requests\Infrastructure\LoadBalancerRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreLoadBalancerController
{
    /**
     * Adds a load balancer.
     *
     * @param  LoadBalancerRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveLoadBalancer  $save
     * @return RedirectResponse
     */
    public function __invoke(LoadBalancerRequest $request, #[CurrentUser] User $user, Project $project, SaveLoadBalancer $save): RedirectResponse
    {
        $save->handle($project->account, $user, $request->loadBalancer());

        return to_route('infrastructure.load-balancers', $project)->with('status', __('Load balancer added. Point its hostname at the proxy server.'));
    }
}
