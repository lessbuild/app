<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SaveLoadBalancer;
use App\Http\Requests\Infrastructure\LoadBalancerRequest;
use App\Models\LoadBalancer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateLoadBalancerController
{
    public function __invoke(LoadBalancerRequest $request, #[CurrentUser] User $user, Project $project, LoadBalancer $loadBalancer, SaveLoadBalancer $save): RedirectResponse
    {
        $save->handle($project->account, $user, $request->loadBalancer(), $loadBalancer);

        return to_route('infrastructure.load-balancers', $project)->with('status', __('Load balancer saved.'));
    }
}
