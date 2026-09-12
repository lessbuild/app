<?php

namespace App\Http\Controllers;

use App\Actions\Organization\VerifyEnterpriseSsoAction;
use App\Data\EnterpriseSsoCallbackData;
use App\Http\Requests\EnterpriseSsoCallbackRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\EnterpriseOidc;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EnterpriseSsoController extends Controller
{
    /**
     * Use workspace entitlements to gate enterprise identity-provider verification.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Require the SSO entitlement and redirect to the current workspace's identity-provider authorization URL.
     */
    public function connect(Request $request, EnterpriseOidc $oidc): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        $this->entitlements->enforce($organization, 'sso');

        return redirect()->away($oidc->authorizationUrl($request->session(), $organization));
    }

    /**
     * Validate the authorization code and state, verify the entitled workspace's OIDC response, and redirect to the dashboard.
     *
     * Provider verification failures become a generic SSO validation error.
     */
    public function callback(EnterpriseSsoCallbackRequest $request, VerifyEnterpriseSsoAction $verify): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Organization $organization */
        $organization = $user->currentOrganization;
        $verify->handle($user, $organization, new EnterpriseSsoCallbackData($request->code(), $request->state()));

        return redirect()->route('dashboard')->with('success', __('Workspace SSO verified.'));
    }
}
