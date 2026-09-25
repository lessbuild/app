<?php

namespace App\Modules\Deployer\Http\Requests;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Modules\Deployer\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BillingCheckoutRequest extends FormRequest
{
    /**
     * Preserve the existing paid-plan 404 and billing-permission 403 ordering before interval validation.
     */
    public function authorize(ProductAuthentication $authentication, ProductWorkspaceAccess $workspaceAccess): bool
    {
        $plan = (string) $this->route('plan');
        abort_unless(array_key_exists($plan, config('billing.plans')) && $plan !== 'free', 404);

        $user = $this->user();
        $organization = $user?->currentOrganization;
        abort_unless($organization instanceof Organization, 403);

        if ($authentication->usesCoreAuthority('deployer')) {
            $platformUser = $this->attributes->get('platform_user');
            abort_unless(
                $platformUser instanceof PlatformUser
                    && $workspaceAccess->canManageBilling($platformUser, 'deployer', 'organization', $organization->getKey()),
                403,
            );
        } else {
            abort_unless($user?->can('manageBilling', $organization) ?? false, 403);
        }

        return true;
    }

    /** Validate the optional monthly/yearly billing interval. */
    public function rules(): array
    {
        return [
            'interval' => ['sometimes', Rule::in(['monthly', 'yearly'])],
        ];
    }

    /** Return the validated billing interval with the existing monthly default. */
    public function billingInterval(): string
    {
        $interval = $this->validated('interval');

        return is_string($interval) ? $interval : 'monthly';
    }
}
