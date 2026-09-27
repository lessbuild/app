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

final class UpdateLoadBalancerNodeController
{
    public function __invoke(LoadBalancerNodeRequest $request, #[CurrentUser] User $user, Project $project, LoadBalancer $loadBalancer, string $node, SaveLoadBalancerNode $save): RedirectResponse
    {
        $save->handle($user, $loadBalancer, $request->node(), $loadBalancer->nodes()->findOrFail((int) $node));

        return to_route('infrastructure.load-balancers', $project)->with('status', __('Node saved.'));
    }
}
