<?php

namespace App\Http\Controllers;

use App\Actions\LoadBalancer\AddLoadBalancerNodeAction;
use App\Actions\LoadBalancer\CreateLoadBalancerAction;
use App\Actions\LoadBalancer\DeleteLoadBalancerAction;
use App\Actions\LoadBalancer\DeleteLoadBalancerNodeAction;
use App\Actions\LoadBalancer\QueueLoadBalancerApplyAction;
use App\Exceptions\LoadBalancerOperationException;
use App\Http\Requests\StoreLoadBalancerNodeRequest;
use App\Http\Requests\StoreLoadBalancerRequest;
use App\Models\Environment;
use App\Models\LoadBalancer;
use App\Models\LoadBalancerNode;
use App\Models\Organization;
use App\Models\Server;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoadBalancerController extends Controller
{
    /**
     * Use workspace entitlements to gate high-availability configuration changes.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Render current-workspace balancers, environment placements, active servers, and management availability.
     */
    public function index(Request $request): View
    {
        $organization = $request->user()->currentOrganization;

        return view('load-balancers.index', [
            'loadBalancers' => $organization->loadBalancers()->with(['environment.project', 'server', 'nodes.server'])->get(),
            'environments' => $organization->projects()->with('environments')->get()->pluck('environments')->flatten(),
            'servers' => $organization->servers()->where('provisioning_status', 'active')->orderBy('name')->get(),
            'canManage' => $organization->permits($request->user(), 'manage')
                && $this->entitlements->allows($organization, 'high_availability'),
            'featureAvailable' => $this->entitlements->allows($organization, 'high_availability'),
        ]);
    }

    /**
     * Validate a workspace environment, dedicated server, hostname, and health path before creating a balancer.
     *
     * @return RedirectResponse A prompt to add application nodes after the balancer is created.
     */
    public function store(StoreLoadBalancerRequest $request, CreateLoadBalancerAction $createLoadBalancer): RedirectResponse
    {
        $this->authorize('create', LoadBalancer::class);
        /** @var Organization $organization */
        $organization = $request->user()->currentOrganization;
        $data = $request->validated();
        $environment = Environment::findOrFail($data['environment_id']);
        $server = Server::findOrFail($data['server_id']);
        try {
            $createLoadBalancer->handle($organization, $request->user(), $environment, $server, $data);
        } catch (LoadBalancerOperationException $exception) {
            abort(422, $exception->getMessage());
        }

        return back()->with('success', __('Load balancer created. Add at least two application nodes.'));
    }

    /**
     * Validate a distinct workspace server, port, and weight, then add its node and queue balancer configuration.
     */
    public function storeNode(StoreLoadBalancerNodeRequest $request, LoadBalancer $loadBalancer, AddLoadBalancerNodeAction $addNode): RedirectResponse
    {
        $this->authorize('manage', $loadBalancer);
        $data = $request->validated();
        $server = $loadBalancer->organization->servers()->findOrFail($data['server_id']);
        try {
            $addNode->handle($loadBalancer, $server, $data);
        } catch (LoadBalancerOperationException $exception) {
            abort(422, $exception->getMessage());
        }

        return back()->with('success', __('Application node added and configuration queued.'));
    }

    /**
     * Authorize the bound balancer and queue configuration generation, then redirect with an acknowledgement.
     */
    public function apply(LoadBalancer $loadBalancer, QueueLoadBalancerApplyAction $queueApply): RedirectResponse
    {
        $this->manage($loadBalancer);
        $queueApply->handle($loadBalancer);

        return back()->with('success', __('Load-balancer configuration queued.'));
    }

    /**
     * Authorize the node's workspace balancer, remove the node, and queue the updated configuration.
     */
    public function destroyNode(LoadBalancerNode $node, DeleteLoadBalancerNodeAction $deleteNode): RedirectResponse
    {
        $balancer = $node->loadBalancer;
        $this->manage($balancer);
        $deleteNode->handle($node);

        return back()->with('success', __('Node removed.'));
    }

    /**
     * Authorize the bound balancer, queue remote removal, delete its record, and redirect with DNS cleanup guidance.
     */
    public function destroy(LoadBalancer $loadBalancer, DeleteLoadBalancerAction $deleteLoadBalancer): RedirectResponse
    {
        $this->manage($loadBalancer);
        $deleteLoadBalancer->handle($loadBalancer);

        return back()->with('success', __('Load balancer removed. Remove its DNS record if it is no longer used.'));
    }

    /**
     * Require ownership by the current workspace, management permission, and the high-availability entitlement.
     */
    private function manage(LoadBalancer $loadBalancer): void
    {
        $this->authorize('manage', $loadBalancer);
        $this->entitlements->enforce($loadBalancer->organization, 'high_availability');
    }
}
