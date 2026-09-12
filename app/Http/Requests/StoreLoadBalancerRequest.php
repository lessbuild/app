<?php

namespace App\Http\Requests;

use App\Models\LoadBalancer;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoadBalancerRequest extends FormRequest
{
    /**
     * Preserve manager and high-availability entitlement checks before load-balancer validation.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->can('create', LoadBalancer::class) || $user->currentOrganization === null) {
            return false;
        }

        app(Entitlements::class)->enforce($user->currentOrganization, 'high_availability');

        return true;
    }

    /**
     * Validate workspace environment placement, dedicated server, hostname, and health path.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $organization = $this->user()->currentOrganization;

        return [
            'environment_id' => ['required', Rule::exists('environments', 'id')->whereIn('project_id', $organization->projects()->pluck('id'))],
            'server_id' => ['required', Rule::exists('servers', 'id')->where('organization_id', $organization->id)],
            'hostname' => ['required', 'string', 'max:253', 'lowercase', 'regex:/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/', 'unique:load_balancers,hostname'],
            'health_path' => ['required', 'string', 'max:255', 'regex:#\A/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*\z#'],
        ];
    }
}
