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
    public function __construct(private readonly Entitlements $entitlements) {}

    public function connect(Request $request, EnterpriseOidc $oidc): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        $this->entitlements->enforce($organization, 'sso');

        return redirect()->away($oidc->authorizationUrl($request, $organization));
    }

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
