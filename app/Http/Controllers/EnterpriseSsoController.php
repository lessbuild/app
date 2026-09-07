<?php

namespace App\Http\Controllers;

use App\Services\EnterpriseOidc;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

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

        return redirect()->away($oidc->authorizationUrl($request, $organization));
    }

    /**
     * Validate the authorization code and state, verify the entitled workspace's OIDC response, and redirect to the dashboard.
     *
     * Provider verification failures become a generic SSO validation error.
     */
    public function callback(Request $request, EnterpriseOidc $oidc): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        $this->entitlements->enforce($organization, 'sso');
        $request->validate(['code' => ['required', 'string', 'max:4000'], 'state' => ['required', 'string', 'max:200']]);
        try {
            $oidc->verify($request, $organization);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['sso' => __('SSO verification failed. Please try again or contact a workspace administrator.')]);
        }

        return redirect()->route('dashboard')->with('success', __('Workspace SSO verified.'));
    }
}
