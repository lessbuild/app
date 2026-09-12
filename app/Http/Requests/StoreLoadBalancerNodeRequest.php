<?php

namespace App\Http\Requests;

use App\Models\LoadBalancer;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoadBalancerNodeRequest extends FormRequest
{
    /**
     * Preserve balancer management and high-availability entitlement checks before node validation.
     */
    public function authorize(): bool
    {
        $loadBalancer = $this->route('loadBalancer');
        $user = $this->user();
        if (! $loadBalancer instanceof LoadBalancer || $user === null || ! $user->can('manage', $loadBalancer)) {
            return false;
        }

        app(Entitlements::class)->enforce($loadBalancer->organization, 'high_availability');

        return true;
    }

    /**
     * Validate a distinct workspace server, upstream port, and weight.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var LoadBalancer $loadBalancer */
        $loadBalancer = $this->route('loadBalancer');

        return [
            'server_id' => [
                'required',
                Rule::exists('servers', 'id')->where('organization_id', $loadBalancer->organization_id),
                Rule::unique('load_balancer_nodes')->where('load_balancer_id', $loadBalancer->id),
            ],
            'upstream_port' => ['required', 'integer', 'between:1,65535'],
            'weight' => ['required', 'integer', 'between:1,10'],
        ];
    }
}
