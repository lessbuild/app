<?php

namespace App\Http\Controllers;

use App\Jobs\ApplyLoadBalancerJob;
use App\Jobs\RemoveLoadBalancerJob;
use App\Models\Environment;
use App\Models\LoadBalancer;
use App\Models\LoadBalancerNode;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
    public function store(Request $request): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization->permits($request->user(), 'manage'), 403);
        $this->entitlements->enforce($organization, 'high_availability');
        $data = $request->validate([
            'environment_id' => ['required', Rule::exists('environments', 'id')->whereIn('project_id', $organization->projects()->pluck('id'))],
            'server_id' => ['required', Rule::exists('servers', 'id')->where('organization_id', $organization->id)],
            'hostname' => ['required', 'string', 'max:253', 'lowercase', 'regex:/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/', 'unique:load_balancers,hostname'],
            'health_path' => ['required', 'string', 'max:255', 'regex:#\A/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*\z#'],
        ]);
        $environment = Environment::findOrFail($data['environment_id']);
        abort_if((int) $environment->server_id === (int) $data['server_id'], 422, 'Use a dedicated server for the load balancer.');
        $balancer = $organization->loadBalancers()->create([...$data, 'created_by' => $request->user()->id]);

        return back()->with('success', __('Load balancer created. Add at least two application nodes.'));
    }

    /**
     * Validate a distinct workspace server, port, and weight, then add its node and queue balancer configuration.
     */
    public function storeNode(Request $request, LoadBalancer $loadBalancer): RedirectResponse
    {
        $this->manage($loadBalancer);
        $data = $request->validate([
            'server_id' => ['required', Rule::exists('servers', 'id')->where('organization_id', $loadBalancer->organization_id), Rule::unique('load_balancer_nodes')->where('load_balancer_id', $loadBalancer->id)],
            'upstream_port' => ['required', 'integer', 'between:1,65535'],
            'weight' => ['required', 'integer', 'between:1,10'],
        ]);
        abort_if((int) $loadBalancer->server_id === (int) $data['server_id'], 422, 'The load balancer cannot route to itself.');
        $loadBalancer->nodes()->create([...$data, 'is_enabled' => true]);
        ApplyLoadBalancerJob::dispatch($loadBalancer->id);

        return back()->with('success', __('Application node added and configuration queued.'));
    }

    /**
     * Authorize the bound balancer and queue configuration generation, then redirect with an acknowledgement.
     */
    public function apply(LoadBalancer $loadBalancer): RedirectResponse
    {
        $this->manage($loadBalancer);
        ApplyLoadBalancerJob::dispatch($loadBalancer->id);

        return back()->with('success', __('Load-balancer configuration queued.'));
    }

    /**
     * Authorize the node's workspace balancer, remove the node, and queue the updated configuration.
     */
    public function destroyNode(LoadBalancerNode $node): RedirectResponse
    {
        $balancer = $node->loadBalancer;
        $this->manage($balancer);
        $node->delete();
        ApplyLoadBalancerJob::dispatch($balancer->id);

        return back()->with('success', __('Node removed.'));
    }

    /**
     * Authorize the bound balancer, queue remote removal, delete its record, and redirect with DNS cleanup guidance.
     */
    public function destroy(LoadBalancer $loadBalancer): RedirectResponse
    {
        $this->manage($loadBalancer);
        RemoveLoadBalancerJob::dispatch($loadBalancer->server_id, $loadBalancer->id);
        $loadBalancer->delete();

        return back()->with('success', __('Load balancer removed. Remove its DNS record if it is no longer used.'));
    }

    /**
     * Require ownership by the current workspace, management permission, and the high-availability entitlement.
     */
    private function manage(LoadBalancer $loadBalancer): void
    {
        abort_unless($loadBalancer->organization_id === request()->user()->current_organization_id
            && $loadBalancer->organization->permits(request()->user(), 'manage'), 403);
        $this->entitlements->enforce($loadBalancer->organization, 'high_availability');
    }
}
